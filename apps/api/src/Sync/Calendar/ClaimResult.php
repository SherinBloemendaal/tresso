<?php

declare(strict_types=1);

namespace App\Sync\Calendar;

final readonly class ClaimResult
{
    /**
     * @param list<ClaimedOperation> $operations
     */
    public function __construct(public array $operations)
    {
    }
}
