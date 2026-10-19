<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Entity\MailAccount;
use App\Entity\Message;

/**
 * A message the user wrote, before it becomes MIME.
 */
final readonly class Composition
{
    /**
     * @param list<array{address: string, name: ?string}> $to
     * @param list<array{address: string, name: ?string}> $cc
     * @param list<array{address: string, name: ?string}> $bcc
     * @param list<OutgoingAttachment>                     $attachments
     */
    public function __construct(
        public MailAccount $account,
        public array $to,
        public array $cc,
        public array $bcc,
        public string $subject,
        public string $html,
        public ?Message $inReplyTo,
        public \DateTimeImmutable $sendAt,
        public array $attachments = [],
        /** An invitation, cancellation or reply that goes with the message. */
        public ?CalendarPart $calendar = null,
    ) {
    }
}
