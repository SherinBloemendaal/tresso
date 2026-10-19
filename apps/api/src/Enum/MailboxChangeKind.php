<?php

declare(strict_types=1);

namespace App\Enum;

enum MailboxChangeKind: string
{
    /** Set \Seen and/or \Flagged on every copy of the message. */
    case Flags = 'flags';
    /** Move one copy of the message to another folder. */
    case Move = 'move';
}
