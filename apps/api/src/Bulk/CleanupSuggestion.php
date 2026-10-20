<?php

declare(strict_types=1);

namespace App\Bulk;

use App\Enum\BulkArea;
use App\Enum\MessageAction;

/** One suggestion of {@see CleanupSuggestions}: what a rule found, and the bulk action that cleans it up. */
final readonly class CleanupSuggestion
{
    public const string NEWSLETTER = 'newsletter';
    public const string UNOPENED_SENDER = 'unopenedSender';
    public const string OLD_IN_SMART_INBOX = 'oldInSmartInbox';
    public const string LARGE_OLD = 'largeOld';

    public function __construct(
        public string $id,
        public string $kind,
        public MessageAction $action,
        public BulkArea $in,
        public int $conversations,
        public ?int $unread = null,
        public ?int $bytes = null,
        public ?string $sender = null,
        public ?string $senderName = null,
        public ?string $smartInboxId = null,
        /** The days the rule looked back to see whether the user opened any of the sender's mail. */
        public ?int $windowDays = null,
        /** How many messages the sender sent in those days, none of which was opened. */
        public ?int $windowReceived = null,
        public ?int $olderThanDays = null,
        public ?int $largerThan = null,
    ) {
    }
}
