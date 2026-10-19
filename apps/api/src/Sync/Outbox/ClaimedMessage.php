<?php

declare(strict_types=1);

namespace App\Sync\Outbox;

use App\Entity\OutgoingMessage;

/**
 * A message a worker must send now. When `attempt` is above 1 an earlier attempt may have reached the server
 * already: the worker checks the Sent folder for `messageId` before sending again. `lastAttempt` says a retry report
 * ends the message as failed, so the worker can word its error for the user as final.
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
        public bool $lastAttempt,
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
            $message->getAttempts() >= OutgoingMessage::MAX_ATTEMPTS,
        );
    }
}
