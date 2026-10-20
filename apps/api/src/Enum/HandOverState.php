<?php

declare(strict_types=1);

namespace App\Enum;

enum HandOverState: string
{
    /** Forwarded and waiting for the delegate; listed in "Waiting on". */
    case Open = 'open';
    /** The delegate replied in the conversation. */
    case Replied = 'replied';
    /** The user marked it done. */
    case Done = 'done';
}
