<?php

declare(strict_types=1);

namespace App\Sync\Ingest;

final readonly class FolderSyncResult
{
    /**
     * @param list<FolderState> $folders
     */
    public function __construct(public array $folders)
    {
    }
}
