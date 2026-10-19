<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\SyncLease;
use App\Sync\AccountLeaser;
use App\Sync\LeasedAccount;
use App\Sync\LeaseResult;

/**
 * @implements ProcessorInterface<SyncLease, LeaseResult>
 */
final readonly class SyncLeaseProcessor implements ProcessorInterface
{
    public function __construct(private AccountLeaser $leaser)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LeaseResult
    {
        $accounts = array_map(
            static fn (array $account): LeasedAccount => new LeasedAccount($account['id'], $account['emailAddress'], $account['provider'], $account['leaseExpiresAt']),
            $this->leaser->lease($data->workerId, $data->capacity),
        );

        return new LeaseResult(AccountLeaser::LEASE_SECONDS, $accounts);
    }
}
