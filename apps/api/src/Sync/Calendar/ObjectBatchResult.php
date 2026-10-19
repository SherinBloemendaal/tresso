<?php

declare(strict_types=1);

namespace App\Sync\Calendar;

final readonly class ObjectBatchResult
{
    public function __construct(
        public int $stored,
        public int $removed,
        /** Resources with a local change on its way to the provider, or unreadable ones. */
        public int $skipped,
    ) {
    }
}
