<?php

declare(strict_types=1);

namespace App\Enum;

enum FolderRole: string
{
    case Inbox = 'inbox';
    case Sent = 'sent';
    case Drafts = 'drafts';
    case Trash = 'trash';
    case Spam = 'spam';
    case Archive = 'archive';
    case All = 'all';
    case Custom = 'custom';
}
