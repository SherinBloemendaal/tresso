<?php

declare(strict_types=1);

namespace App\Bulk;

/** How far a run of a bulk action got. */
enum BulkRun
{
    /** Batches are left: run again right away. */
    case More;
    /** The mail servers are behind on this user's changes: run again in a while. */
    case Wait;
    /** Nothing is left (or the action is gone or stopped). */
    case Done;
}
