<?php

declare(strict_types=1);

namespace App\Enum;

enum MessageAction: string
{
    case MarkRead = 'markRead';
    case MarkUnread = 'markUnread';
    case Flag = 'flag';
    case Unflag = 'unflag';
    /** Out of the inbox into the account's Archive folder. */
    case Archive = 'archive';
    /** Into the account's Trash folder. */
    case Trash = 'trash';
    /** Into the folder given as `folderId`. */
    case Move = 'move';
}
