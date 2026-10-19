<?php

declare(strict_types=1);

namespace App\Sync\Outbox;

final readonly class ClaimResult
{
    /** @param list<ClaimedMessage> $messages */
    public function __construct(public array $messages)
    {
    }
}
