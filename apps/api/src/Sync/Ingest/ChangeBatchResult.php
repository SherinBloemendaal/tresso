<?php

declare(strict_types=1);

namespace App\Sync\Ingest;

final readonly class ChangeBatchResult
{
    public function __construct(
        public int $updated,
        /** Locations removed from this folder. */
        public int $removed,
        /** Flag changes for messages that were not ingested yet; the worker should send them as new messages. */
        public int $unknown,
        /** Messages deleted because they are in no folder anymore. */
        public int $deleted,
    ) {
    }
}
