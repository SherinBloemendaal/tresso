<?php

declare(strict_types=1);

namespace App\Sync\Ingest;

final readonly class MessageBatchResult
{
    public function __construct(
        /** New messages. */
        public int $stored,
        /** Known messages that are now also in this folder (copies, Gmail labels). */
        public int $linked,
        /** Messages already in this folder; their flags were refreshed. */
        public int $updated,
    ) {
    }
}
