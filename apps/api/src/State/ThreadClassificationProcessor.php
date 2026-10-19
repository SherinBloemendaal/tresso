<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\SmartInbox\ThreadClassificationInput;
use App\ApiResource\Output\RememberedSender;
use App\ApiResource\ThreadClassificationResource;
use App\Classification\ClassificationReason;
use App\Classification\ClassificationScheduler;
use App\Classification\MailClassification;
use App\Entity\SenderRule;
use App\Entity\Thread;
use App\Entity\User;
use App\Enum\Verdict;
use App\Repository\CategoryRepository;
use App\Repository\SenderRuleRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Puts a conversation where the user says it belongs and remembers that for its sender.
 *
 * @implements ProcessorInterface<ThreadClassificationInput, ThreadClassificationResource>
 */
final readonly class ThreadClassificationProcessor implements ProcessorInterface
{
    public function __construct(
        private CategoryRepository $smartInboxes,
        private SenderRuleRepository $rules,
        private MailClassification $classification,
        private ClassificationScheduler $scheduler,
        private CurrentUser $currentUser,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ThreadClassificationResource
    {
        $owner = $this->currentUser->get();
        $thread = $this->ownedThread($owner, $uriVariables['threadId'] ?? null);

        // One transaction, so change numbers for client sync commit in order.
        return $this->entityManager->wrapInTransaction(function () use ($data, $owner, $thread): ThreadClassificationResource {
            $smartInbox = $this->smartInboxes->findOneOwnedBy($owner, $data->smartInboxId)
                ?? throw new ValidationException(new ConstraintViolationList([
                    new ConstraintViolation('This smart inbox does not exist.', null, [], $data, 'smartInboxId', $data->smartInboxId),
                ]));
            $explicitVerdict = null === $data->verdict ? null : Verdict::from($data->verdict);
            $verdict = $explicitVerdict ?? $smartInbox->getVerdict();

            // Corrections and classification runs of this user take turns, so neither overwrites the other, and two
            // corrections for one sender never race for the same sender rule.
            $this->classification->lock($owner->getId());
            $message = $this->classification->newestIncoming($thread);
            $blocking = null === $message ? null : $smartInbox->exclusionOtherThan($message->getFromAddress());
            if (null !== $blocking) {
                // A rule for the sender would never apply: the classifier skips smart inboxes that exclude the sender.
                throw new ValidationException(new ConstraintViolationList([new ConstraintViolation(
                    \sprintf('Mail from %s is on the Never include list of %s. Take it off that list first.', $blocking, $smartInbox->getName()),
                    null, [], $data, 'smartInboxId', $data->smartInboxId,
                )]));
            }

            $this->classification->placeByUser($thread, $message, $smartInbox, $verdict);

            $rule = null;
            if (null !== $message) {
                $sender = $message->getFromAddress();
                $rule = $this->rules->findOneBy(['owner' => $owner, 'sender' => $sender]);
                if (null === $rule) {
                    $rule = new SenderRule($owner, $sender, $smartInbox, $explicitVerdict);
                    $this->entityManager->persist($rule);
                } else {
                    $rule->change($smartInbox, $explicitVerdict);
                }
                $smartInbox->stopExcluding($sender);
            }
            $this->entityManager->flush();
            if (null !== $rule) {
                $this->scheduler->senderChanged($owner->getId(), $rule->getSender());
            }

            return new ThreadClassificationResource(
                $thread->getId()->toRfc4122(),
                $smartInbox->getId()->toRfc4122(),
                $verdict->value,
                ClassificationReason::USER,
                null === $rule ? null : RememberedSender::fromEntity($rule),
            );
        });
    }

    private function ownedThread(User $owner, mixed $id): Thread
    {
        $thread = \is_string($id) && Uuid::isValid($id) ? $this->entityManager->find(Thread::class, Uuid::fromString($id)) : null;
        if (null === $thread || !$thread->getMailAccount()->getOwner()->getId()->equals($owner->getId())) {
            throw new NotFoundHttpException('Conversation not found.');
        }

        return $thread;
    }
}
