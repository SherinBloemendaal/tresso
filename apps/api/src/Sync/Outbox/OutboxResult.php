<?php

declare(strict_types=1);

namespace App\Sync\Outbox;

final readonly class OutboxResult
{
    public function __construct(public string $status)
    {
    }
}
