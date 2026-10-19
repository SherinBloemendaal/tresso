<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\SyncLease;
use App\Outbox\Outbox;
use App\Repository\OutgoingMessageRepository;
use App\Sync\AccountLeaser;
use App\Sync\LeaseResult;
use App\Sync\LeasedAccount;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @implements ProcessorInterface<SyncLease, LeaseResult>
 */
final readonly class SyncLeaseProcessor implements ProcessorInterface
{
    public function __construct(
        private AccountLeaser $leaser,
        private Outbox $outbox,
        private OutgoingMessageRepository $outgoing,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LeaseResult
    {
        $accounts = array_map(
            static fn (array $account): LeasedAccount => new LeasedAccount($account['id'], $account['emailAddress'], $account['provider'], $account['leaseExpiresAt']),
            $this->leaser->lease($data->workerId, $data->capacity),
        );
        $accountIds = array_map(static fn (LeasedAccount $account): string => $account->id, $accounts);

        // Every heartbeat also releases scheduled mail whose time has come, in case its release message got lost, and
        // tells the worker which accounts have mail to send, in case it missed the realtime notification.
        $now = new \DateTimeImmutable();
        $this->entityManager->wrapInTransaction(fn () => $this->outbox->releaseDue($accountIds, $now));

        return new LeaseResult(AccountLeaser::LEASE_SECONDS, $accounts, $this->outgoing->accountsWithClaimable($accountIds, $now));
    }
}
