<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Sync\AccountStatusInput;
use App\ApiResource\Input\Sync\ChangeBatchInput;
use App\ApiResource\Input\Sync\FolderListInput;
use App\ApiResource\Input\Sync\MessageBatchInput;
use App\ApiResource\Input\Sync\WorkerRequest;
use App\Entity\MailAccount;
use App\Enum\MailAccountStatus;
use App\Sync\Ingest\AccountStatusResult;
use App\Sync\Ingest\ChangeApplier;
use App\Sync\Ingest\ChangeBatchResult;
use App\Sync\Ingest\FolderSynchronizer;
use App\Sync\Ingest\FolderSyncResult;
use App\Sync\Ingest\MessageBatchResult;
use App\Sync\Ingest\MessageIngestor;
use App\Sync\LeaseGuard;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Checks the lease, then hands the report to the matching ingest service.
 *
 * @implements ProcessorInterface<WorkerRequest, FolderSyncResult|MessageBatchResult|ChangeBatchResult|AccountStatusResult>
 */
final readonly class SyncIngestProcessor implements ProcessorInterface
{
    public function __construct(
        private LeaseGuard $leaseGuard,
        private FolderSynchronizer $folders,
        private MessageIngestor $messages,
        private ChangeApplier $changes,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FolderSyncResult|MessageBatchResult|ChangeBatchResult|AccountStatusResult
    {
        $account = $this->leaseGuard->leasedAccount($uriVariables['accountId'] ?? null, $data->workerId);

        $result = $this->entityManager->wrapInTransaction(fn (): FolderSyncResult|MessageBatchResult|ChangeBatchResult|AccountStatusResult => match (true) {
            $data instanceof FolderListInput => new FolderSyncResult($this->folders->sync($account, $data->folders)),
            $data instanceof MessageBatchInput => $this->messages->ingest($account, $data),
            $data instanceof ChangeBatchInput => $this->changes->apply($account, $data),
            $data instanceof AccountStatusInput => $this->updateStatus($account, $data),
            default => throw new \LogicException(\sprintf('Unexpected ingest input %s.', $data::class)),
        });

        // The services also work in SQL; never let a later request in this process see stale entities.
        $this->entityManager->clear();

        return $result;
    }

    private function updateStatus(MailAccount $account, AccountStatusInput $input): AccountStatusResult
    {
        $status = $input->status ?? throw new \LogicException('Validation guarantees a status.');
        $account->setStatus($status, $input->detail);
        if (MailAccountStatus::Active === $status) {
            $account->markSynced(new \DateTimeImmutable());
        }
        $this->entityManager->flush();

        return new AccountStatusResult($account->getStatus());
    }
}
