<?php

declare(strict_types=1);

namespace App\Sync;

final readonly class LeaseResult
{
    /**
     * @param list<LeasedAccount> $accounts
     * @param list<string>        $outbox   ids of leased accounts with mail to send now
     */
    public function __construct(
        public int $leaseSeconds,
        public array $accounts,
        public array $outbox,
    ) {
    }
}
