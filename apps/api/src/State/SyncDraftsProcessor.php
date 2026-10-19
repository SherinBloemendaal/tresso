<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Sync\DraftClaimInput;
use App\ApiResource\Input\Sync\DraftFiledInput;
use App\ApiResource\Input\Sync\DraftRemovedInput;
use App\Entity\Draft;
use App\Entity\DraftCopyRemoval;
use App\Outbox\DraftFiling;
use App\Sync\Drafts\DraftWork;
use App\Sync\Drafts\DraftWorkResult;
use App\Sync\LeaseGuard;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<DraftClaimInput|DraftFiledInput|DraftRemovedInput, DraftWork|DraftWorkResult>
 */
final readonly class SyncDraftsProcessor implements ProcessorInterface
{
    public function __construct(
        private LeaseGuard $leaseGuard,
        private DraftFiling $filing,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DraftWork|DraftWorkResult
    {
        $result = $this->entityManager->wrapInTransaction(function () use ($data, $uriVariables): DraftWork|DraftWorkResult {
            $account = $this->leaseGuard->leasedAccount($uriVariables['accountId'] ?? null, $data->workerId);
            $now = new \DateTimeImmutable();

            if ($data instanceof DraftClaimInput) {
                $work = $this->filing->claim($account, $data->workerId, $now);

                return new DraftWork(
                    array_map(static fn (array $file): array => [
                        'draftId' => $file['draft']->getId()->toRfc4122(),
                        'version' => $file['version'],
                        'rawObjectKey' => $file['rawObjectKey'],
                        'messageId' => $file['messageId'],
                        'previousMessageId' => $file['previousMessageId'],
                    ], $work['files']),
                    array_map(static fn (DraftCopyRemoval $removal): array => ['id' => $removal->getId()->toRfc4122(), 'messageId' => $removal->getMessageId()], $work['removals']),
                );
            }

            if ($data instanceof DraftRemovedInput) {
                $removal = $this->find(DraftCopyRemoval::class, $uriVariables['removalId'] ?? null);
                if (!$removal instanceof DraftCopyRemoval || !$removal->getMailAccount()->getId()->equals($account->getId())) {
                    throw new NotFoundHttpException('No such removal for this account.');
                }
                if (!$removal->isClaimedBy($data->workerId, $now)) {
                    throw new ConflictHttpException('This worker does not hold the claim on the removal (any more).');
                }
                $this->filing->removed($removal);

                return new DraftWorkResult(true);
            }

            $draft = $this->find(Draft::class, $uriVariables['draftId'] ?? null);
            if (!$draft instanceof Draft || $draft->isSending()) {
                // Sent or deleted while it was being filed: the copy that just landed has to go again. A draft that is
                // being sent comes back, if at all, filed anew.
                $this->entityManager->persist(new DraftCopyRemoval($account, $data->messageId));
                $this->entityManager->flush();

                return new DraftWorkResult(false);
            }
            if (!$draft->isFilingClaimedBy($data->workerId, $now)) {
                throw new ConflictHttpException('This worker does not hold the claim on the draft (any more).');
            }
            if (!$draft->getMailAccount()?->getId()->equals($account->getId())) {
                // Moved to another account while it was being filed.
                $this->entityManager->persist(new DraftCopyRemoval($account, $data->messageId));
                $draft->releaseFiling();
                $this->entityManager->flush();

                return new DraftWorkResult(false);
            }
            $this->filing->filed($draft, $data->version, $data->messageId);

            return new DraftWorkResult(true);
        });
        $this->entityManager->clear();

        return $result;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function find(string $class, mixed $id): ?object
    {
        return \is_string($id) && Uuid::isValid($id) ? $this->entityManager->find($class, Uuid::fromString($id), LockMode::PESSIMISTIC_WRITE) : null;
    }
}
