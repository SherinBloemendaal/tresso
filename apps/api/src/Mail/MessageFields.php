<?php

declare(strict_types=1);

namespace App\Mail;

use App\Entity\MailNotification;
use App\Entity\Message;
use App\Entity\MessageText;
use App\Security\Crypto\FieldVault;
use Symfony\Component\Uid\Uuid;

/**
 * The mail text kept sealed with the owner's field key: the snippet, the body text for search, and what a mail
 * notification says (subject and the start of the text). All are only read whole, never queried, so the database
 * needs no plaintext of them. Rows from before encryption still hold plaintext until re-encryption seals them; reading
 * takes either.
 */
final readonly class MessageFields
{
    public const int SNIPPET_LENGTH = 512;

    public function __construct(private FieldVault $vault)
    {
    }

    public function sealSnippet(Message $message, string $snippet): void
    {
        $message->sealSnippet($this->vault->seal(self::owner($message), self::snippetContext($message->getId()), mb_substr($snippet, 0, self::SNIPPET_LENGTH)));
    }

    public function snippet(Message $message): string
    {
        return $this->snippetOf(self::owner($message), $message->getId(), $message->getSealedSnippet(), $message->getLegacySnippet());
    }

    /** The snippet from a row read in SQL. */
    public function snippetOf(Uuid $owner, Uuid $messageId, ?string $sealed, string $legacy): string
    {
        return null === $sealed ? $legacy : $this->vault->open($owner, self::snippetContext($messageId), $sealed);
    }

    public function newText(Message $message, string $text): MessageText
    {
        return new MessageText($message, $this->vault->seal(self::owner($message), self::textContext($message->getId()), mb_substr($text, 0, MessageText::MAX_LENGTH)));
    }

    public function sealText(MessageText $text, string $plaintext): void
    {
        $message = $text->getMessage();
        $text->seal($this->vault->seal(self::owner($message), self::textContext($message->getId()), mb_substr($plaintext, 0, MessageText::MAX_LENGTH)));
    }

    public function text(MessageText $text): string
    {
        $message = $text->getMessage();

        return $this->textOf(self::owner($message), $message->getId(), $text->getSealedText(), $text->getLegacyText());
    }

    /** The body text from a row read in SQL; empty when the message has none. */
    public function textOf(Uuid $owner, Uuid $messageId, ?string $sealed, ?string $legacy): string
    {
        return null === $sealed ? ($legacy ?? '') : $this->vault->open($owner, self::textContext($messageId), $sealed);
    }

    /** Seals a snippet read in SQL (plaintext or sealed with an older key) with the owner's current key. */
    public function resealSnippet(Uuid $owner, Uuid $messageId, ?string $sealed, string $legacy): string
    {
        return $this->vault->seal($owner, self::snippetContext($messageId), $this->snippetOf($owner, $messageId, $sealed, $legacy));
    }

    /** Seals a body text read in SQL (plaintext or sealed with an older key) with the owner's current key. */
    public function resealText(Uuid $owner, Uuid $messageId, ?string $sealed, ?string $legacy): string
    {
        return $this->vault->seal($owner, self::textContext($messageId), $this->textOf($owner, $messageId, $sealed, $legacy));
    }

    /** An empty body is stored as none. */
    public function sealNotificationBody(MailNotification $notification, string $body): void
    {
        $notification->sealBody('' === $body ? null : $this->vault->seal($notification->getUser()->getId(), self::notificationContext($notification->getId()), $body));
    }

    public function notificationBody(MailNotification $notification): string
    {
        return $this->notificationBodyOf($notification->getUser()->getId(), $notification->getId(), $notification->getSealedBody(), $notification->getLegacyBody());
    }

    /** A notification body from a row read in SQL. */
    public function notificationBodyOf(Uuid $owner, Uuid $notificationId, ?string $sealed, string $legacy): string
    {
        return null === $sealed ? $legacy : $this->vault->open($owner, self::notificationContext($notificationId), $sealed);
    }

    /** Seals a notification body read in SQL (plaintext or sealed with an older key) with the owner's current key. */
    public function resealNotificationBody(Uuid $owner, Uuid $notificationId, ?string $sealed, string $legacy): ?string
    {
        $body = $this->notificationBodyOf($owner, $notificationId, $sealed, $legacy);

        return '' === $body ? null : $this->vault->seal($owner, self::notificationContext($notificationId), $body);
    }

    private static function owner(Message $message): Uuid
    {
        return $message->getMailAccount()->getOwner()->getId();
    }

    private static function snippetContext(Uuid $messageId): string
    {
        return 'tresso:message-snippet:'.$messageId->toRfc4122();
    }

    private static function notificationContext(Uuid $notificationId): string
    {
        return 'tresso:mail-notification-body:'.$notificationId->toRfc4122();
    }

    private static function textContext(Uuid $messageId): string
    {
        return 'tresso:message-text:'.$messageId->toRfc4122();
    }
}
