<?php

declare(strict_types=1);

namespace App\Enum;

enum DraftKind: string
{
    case New = 'new';
    case Reply = 'reply';
    case ReplyAll = 'replyAll';
    case Forward = 'forward';
}
