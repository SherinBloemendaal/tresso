<?php

declare(strict_types=1);

namespace App\Sync;

final readonly class LeasedAccount
{
    public function __construct(
        public string $id,
        public string $emailAddress,
        public string $provider,
        public string $leaseExpiresAt,
    ) {
    }
}
