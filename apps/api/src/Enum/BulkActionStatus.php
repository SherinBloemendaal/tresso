<?php

declare(strict_types=1);

namespace App\Enum;

enum BulkActionStatus: string
{
    /** Batches are still being worked through. */
    case Running = 'running';
    case Done = 'done';
    /** It stopped; what was done so far stays done and can be undone. */
    case Failed = 'failed';
    /** The undo is being worked through. */
    case Undoing = 'undoing';
    case Undone = 'undone';
}
