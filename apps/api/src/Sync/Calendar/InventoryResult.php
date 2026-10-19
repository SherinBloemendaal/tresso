<?php

declare(strict_types=1);

namespace App\Sync\Calendar;

final readonly class InventoryResult
{
    /**
     * @param list<string> $fetch remote ids the worker must fetch and report
     */
    public function __construct(
        public array $fetch,
        public int $removed,
    ) {
    }
}
