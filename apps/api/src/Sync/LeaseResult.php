<?php

declare(strict_types=1);

namespace App\Sync;

final readonly class LeaseResult
{
    /**
     * @param list<LeasedAccount> $accounts
     */
    public function __construct(
        public int $leaseSeconds,
        public array $accounts,
    ) {
    }
}
