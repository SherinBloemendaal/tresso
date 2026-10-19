<?php

declare(strict_types=1);

namespace App\Sync\Outbox;

use App\Entity\OutgoingMessage;

/**
 * A message a worker must send now. When `attempt` is above 1 an earlier attempt may have reached the server
 * already: the worker checks the Sent folder for `messageId` before sending again.
 */
final readonly class ClaimedMessage
{
    /**
     * @param list<string> $recipients every envelope recipient, Bcc included
     */
    public function __construct(
        public string $id,
        public string $rawObjectKey,
        public string $envelopeFrom,
        public array $recipients,
        public string $messageId,
        public int $attempt,
    ) {
    }

    public static function fromEntity(OutgoingMessage $message): self
    {
        return new self(
            $message->getId()->toRfc4122(),
            $message->getRawObjectKey(),
            $message->getMailAccount()->getEmailAddress(),
            $message->getEnvelopeRecipients(),
            $message->getMessageIdHeader(),
            $message->getAttempts(),
        );
    }
}
