<?php

declare(strict_types=1);

namespace App\Sync\Ingest;

/**
 * What the worker needs to continue a folder: the stored cursor, or a reset when UIDVALIDITY changed.
 */
final readonly class FolderState
{
    public function __construct(
        public string $remoteName,
        public ?int $uidValidity,
        public ?string $cursor,
        public bool $reset,
    ) {
    }
}
