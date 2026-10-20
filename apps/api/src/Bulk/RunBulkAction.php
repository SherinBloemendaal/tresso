<?php

declare(strict_types=1);

namespace App\Bulk;

/**
 * Asks for the next batches of a bulk action, or of its undo. Safe to send any number of times: the batches follow
 * the cursor stored with the action.
 */
final readonly class RunBulkAction
{
    public function __construct(public string $bulkActionId)
    {
    }
}
