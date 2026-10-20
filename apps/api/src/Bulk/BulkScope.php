<?php

declare(strict_types=1);

namespace App\Bulk;

use App\Entity\Category;
use App\Entity\MailAccount;
use App\Enum\BulkArea;

/**
 * The conversations a bulk action takes: those of an area (a smart inbox, the inboxes, the whole mailbox), narrowed by
 * account, sender, age and size. The smart inbox and account belong to the user the scope is used for.
 */
final readonly class BulkScope
{
    public function __construct(
        public BulkArea $area,
        public ?Category $smartInbox = null,
        public ?MailAccount $mailAccount = null,
        /** A lower-cased address. */
        public ?string $sender = null,
        /** The conversation's newest message is older than this. */
        public ?\DateTimeImmutable $before = null,
        /** A message of the conversation has at least this many bytes. */
        public ?int $largerThan = null,
    ) {
        if ((BulkArea::SmartInbox === $area) !== (null !== $smartInbox)) {
            throw new \LogicException('A smart inbox is given exactly when the area is a smart inbox.');
        }
        if (null !== $sender && mb_strtolower($sender) !== $sender) {
            throw new \LogicException('The sender is lower-cased.');
        }
    }
}
