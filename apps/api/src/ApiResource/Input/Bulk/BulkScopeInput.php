<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Bulk;

use App\Enum\BulkArea;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Which conversations: those of a smart inbox (`in: smartInbox` with `smartInboxId`), of the inboxes, or of the whole
 * mailbox outside Trash, Spam and Drafts, optionally only one account's, only those with a message from one sender,
 * only those whose newest message is older than a number of days, and only those with a message of a minimum size.
 */
class BulkScopeInput
{
    #[Assert\NotNull(message: 'Choose where the conversations are.')]
    public ?BulkArea $in = null;

    #[Assert\Uuid]
    public ?string $smartInboxId = null;

    #[Assert\Uuid]
    public ?string $mailAccountId = null;

    /** An address; matched without regard to case. */
    #[Assert\Length(max: 320)]
    #[Assert\Regex(pattern: '/^[^@\s]+@[^@\s]+$/', message: 'This is not an email address.')]
    public ?string $sender = null;

    #[Assert\Range(min: 1, max: 36500)]
    public ?int $olderThanDays = null;

    /** In bytes. */
    #[Assert\Range(min: 1)]
    public ?int $largerThan = null;

    #[Assert\Callback]
    public function validateSmartInbox(ExecutionContextInterface $context): void
    {
        if (BulkArea::SmartInbox === $this->in && null === $this->smartInboxId) {
            $context->buildViolation('Choose the smart inbox.')->atPath('smartInboxId')->addViolation();
        }
        if (null !== $this->in && BulkArea::SmartInbox !== $this->in && null !== $this->smartInboxId) {
            $context->buildViolation('Only a smart inbox scope takes a smart inbox.')->atPath('smartInboxId')->addViolation();
        }
    }
}
