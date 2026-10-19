<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\SmartInbox\CreateSmartInbox;
use App\ApiResource\Input\SmartInbox\UpdateSmartInbox;
use App\ApiResource\SmartInboxResource;
use App\Classification\ClassificationScheduler;
use App\Classification\SenderPattern;
use App\Classification\UserLocks;
use App\Entity\Category;
use App\Entity\User;
use App\Enum\Verdict;
use App\Repository\CategoryRepository;
use App\Security\CurrentUser;
use App\Sync\Change\ChangeSequencer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Creates, updates and deletes the signed-in user's smart inboxes. Every change queues a reclassification of recent
 * mail once committed.
 *
 * @implements ProcessorInterface<CreateSmartInbox|UpdateSmartInbox|SmartInboxResource, SmartInboxResource|null>
 */
final readonly class SmartInboxProcessor implements ProcessorInterface
{
    /** Custom smart inboxes per user. */
    public const int MAX_CUSTOM = 50;

    public function __construct(
        private CategoryRepository $smartInboxes,
        private CurrentUser $currentUser,
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private ChangeSequencer $sequencer,
        private ClassificationScheduler $classification,
        private UserLocks $locks,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?SmartInboxResource
    {
        // One transaction per write, so change numbers for client sync commit in order.
        return $this->entityManager->wrapInTransaction(fn (): ?SmartInboxResource => $this->write($data, $operation, $uriVariables));
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function write(mixed $data, Operation $operation, array $uriVariables): ?SmartInboxResource
    {
        $owner = $this->currentUser->get();
        // Writes of one user take turns, so two requests cannot both pass the name and count checks below.
        $this->locks->smartInboxes($owner->getId());
        $existing = $this->smartInboxes->findOwnedBy($owner);

        if ($data instanceof CreateSmartInbox) {
            $custom = array_filter($existing, static fn (Category $c): bool => !$c->isBuiltin());
            if (\count($custom) >= self::MAX_CUSTOM) {
                throw self::invalid($data, 'name', \sprintf('You can have at most %d smart inboxes of your own.', self::MAX_CUSTOM), $data->name);
            }
            $name = trim($data->name);
            $this->assertNameIsFree($data, $existing, $name, null);

            $smartInbox = new Category($owner, $name, trim($data->instructions), Verdict::from($data->verdict));
            $this->applyLists($data, $smartInbox, $data->includeSenders, $data->excludeSenders);
            $smartInbox->setPosition(max(array_map(static fn (Category $c): int => $c->getPosition(), $existing) ?: [-1]) + 1);
            $this->entityManager->persist($smartInbox);
            $this->entityManager->flush();
            $this->classification->rulesChanged($owner->getId());

            return SmartInboxResource::fromEntity($smartInbox);
        }

        $id = $uriVariables['id'] ?? null;
        $smartInbox = (\is_string($id) ? $this->smartInboxes->findOneOwnedBy($owner, $id) : null)
            ?? throw new NotFoundHttpException('Smart inbox not found.');

        if ($operation instanceof DeleteOperationInterface) {
            if ($smartInbox->isBuiltin()) {
                throw self::invalid($data, 'builtin', 'Built-in smart inboxes cannot be deleted.', $smartInbox->getBuiltin()?->value);
            }
            $this->release($owner, $smartInbox);
            $this->entityManager->remove($smartInbox);
            $this->entityManager->flush();
            $this->classification->rulesChanged($owner->getId());

            return null;
        }

        if (!$data instanceof UpdateSmartInbox) {
            throw new \LogicException(\sprintf('Unexpected input %s for %s.', get_debug_type($data), $operation->getName()));
        }

        if (null !== $data->name) {
            $name = trim($data->name);
            $this->assertNameIsFree($data, $existing, $name, $smartInbox);
            $smartInbox->setName($name);
        }
        if (null !== $data->instructions) {
            $smartInbox->setInstructions(trim($data->instructions));
        }
        if (null !== $data->verdict) {
            $smartInbox->setVerdict(Verdict::from($data->verdict));
        }
        $this->applyLists($data, $smartInbox, $data->includeSenders ?? $smartInbox->getIncludeSenders(), $data->excludeSenders ?? $smartInbox->getExcludeSenders());
        $this->entityManager->flush();
        $this->classification->rulesChanged($owner->getId());

        return SmartInboxResource::fromEntity($smartInbox);
    }

    /**
     * @param list<string> $include
     * @param list<string> $exclude
     */
    private function applyLists(object $input, Category $smartInbox, array $include, array $exclude): void
    {
        $include = array_map(SenderPattern::normalize(...), $include);
        $exclude = array_map(SenderPattern::normalize(...), $exclude);
        $both = array_values(array_intersect($include, $exclude));
        if ([] !== $both) {
            throw self::invalid($input, 'excludeSenders', \sprintf('%s cannot be on both lists.', $both[0]), $both[0]);
        }
        $smartInbox->setIncludeSenders($include);
        $smartInbox->setExcludeSenders($exclude);
    }

    /**
     * @param list<Category> $existing
     */
    private function assertNameIsFree(object $input, array $existing, string $name, ?Category $self): void
    {
        foreach ($existing as $other) {
            if ($other !== $self && mb_strtolower($other->getName()) === mb_strtolower($name)) {
                throw self::invalid($input, 'name', 'You already have a smart inbox with this name.', $name);
            }
        }
    }

    /**
     * Takes the smart inbox's conversations and messages out of it before it goes, so clients see them change (the
     * foreign keys would clear them silently). The reclassification queued after the commit gives the conversations
     * a new smart inbox, including those the user had put there.
     */
    private function release(User $owner, Category $smartInbox): void
    {
        $params = ['user' => $owner->getId()->toRfc4122(), 'category' => $smartInbox->getId()->toRfc4122(), 'seq' => $this->sequencer->next($owner->getId())];
        $this->connection->executeStatement(
            'UPDATE thread t SET category_id = NULL, verdict = NULL, classification_reason = NULL, change_seq = :seq
             FROM mail_account a WHERE a.id = t.mail_account_id AND a.owner_id = :user AND t.category_id = :category',
            $params,
        );
        $this->connection->executeStatement(
            'UPDATE message m SET category_id = NULL, change_seq = :seq
             FROM mail_account a WHERE a.id = m.mail_account_id AND a.owner_id = :user AND m.category_id = :category',
            $params,
        );
    }

    private static function invalid(mixed $input, string $path, string $message, mixed $value): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([new ConstraintViolation($message, null, [], $input, $path, $value)]));
    }
}
