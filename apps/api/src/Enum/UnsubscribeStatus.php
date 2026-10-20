<?php

declare(strict_types=1);

namespace App\Enum;

enum UnsubscribeStatus: string
{
    /** Asked for; the request or mail is on its way. */
    case Pending = 'pending';
    /** The sender's server took the request, or the mail went out. */
    case Done = 'done';
    /** Refused, or still failing after every retry; the user may try again. */
    case Failed = 'failed';
}
