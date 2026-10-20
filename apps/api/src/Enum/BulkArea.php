<?php

declare(strict_types=1);

namespace App\Enum;

/** Which conversations a bulk action looks at before its filters narrow them down. */
enum BulkArea: string
{
    /** What a smart inbox lists: its conversations with a message in an inbox or junk folder. */
    case SmartInbox = 'smartInbox';
    /** What the inboxes list: conversations with a message in an inbox folder, Spam left out. */
    case Inbox = 'inbox';
    /** Every conversation with a message outside Trash, Spam and Drafts. */
    case Mailbox = 'mailbox';
}
