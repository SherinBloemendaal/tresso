<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Entity\Message;
use App\Entity\MessageReference;
use App\Storage\BlobVault;
use App\Storage\ObjectNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\MailboxListHeader;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;
use Symfony\Component\Mime\Part\Multipart\RelatedPart;
use Symfony\Component\Mime\Part\TextPart;
use Symfony\Component\Uid\Uuid;

/**
 * Turns a {@see Composition} into the RFC 5322 message that is sent and filed in Sent. Replies carry In-Reply-To and
 * References so every client threads them. Bcc recipients are only on the envelope, never in the message. Attached
 * files are read from object storage. A calendar object (invitation, cancellation, reply) goes along as text/calendar.
 */
final readonly class MimeComposer
{
    /** Clients and servers cope with long chains, but there is no use in sending hundreds of ids. */
    private const int MAX_REFERENCES = 30;

    /** What a Content-ID header can carry: a dot-atom, an "@" and a domain. */
    private const string CONTENT_ID = ';^[A-Za-z0-9!#$%&*+/=?^_{|}~-]+(\.[A-Za-z0-9!#$%&*+/=?^_{|}~-]+)*@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*$;';

    public function __construct(
        private OutgoingHtmlSanitizer $html,
        private EntityManagerInterface $entityManager,
        private BlobVault $blobs,
    ) {
    }

    public function compose(Composition $composition): ComposedMessage
    {
        $messageId = Uuid::v7()->toRfc4122().'@'.self::domain($composition);
        // toString() renders the prepared headers, which leave Bcc out.
        $raw = $this->build($composition, $messageId)->toString();
        if (1 === preg_match('/^Bcc:/mi', substr($raw, 0, (int) strpos($raw, "\r\n\r\n")))) {
            throw new \LogicException('A Bcc header ended up in the message.');
        }

        return new ComposedMessage($raw, $messageId);
    }

    /**
     * The copy of a draft for the account's Drafts folder. Unlike a sent message it keeps Bcc, which other mail apps
     * need to go on writing it, and names the draft it belongs to.
     */
    public function composeDraft(Composition $composition, Uuid $draftId, int $version): ComposedMessage
    {
        $messageId = $draftId->toRfc4122().'.'.$version.'@'.self::domain($composition);
        $email = $this->build($composition, $messageId);
        $email->getHeaders()->addTextHeader('X-Tresso-Draft', $draftId->toRfc4122());
        $raw = $email->toString();
        if ([] !== $composition->bcc) {
            $bcc = new MailboxListHeader('Bcc', self::addresses($composition->bcc));
            $end = (int) strpos($raw, "\r\n\r\n");
            $raw = substr($raw, 0, $end)."\r\n".$bcc->toString().substr($raw, $end);
        }

        return new ComposedMessage($raw, $messageId);
    }

    /**
     * The id an inline part goes out under. Received ids need not be what a Content-ID header can carry (Gmail's
     * "ii_..." has no "@"): those get one of Tresso's own, and the HTML refers to that instead.
     */
    private static function headerContentId(string $contentId): string
    {
        if (1 === preg_match(self::CONTENT_ID, $contentId)) {
            return $contentId;
        }

        return substr(hash('sha256', $contentId), 0, 24).'@inline.tresso.invalid';
    }

    /** Whether the text shows the image: cid:{contentId} whole, not as the start of a longer id. */
    private static function refersTo(string $html, string $contentId): bool
    {
        $reference = preg_quote('cid:'.htmlspecialchars($contentId, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), '/');

        return 1 === preg_match('/'.$reference.'(?=["\')\s]|&(?:quot|apos|#0*3[49]|#x0*2[27]);|$)/i', $html);
    }

    private function build(Composition $composition, string $messageId): Email
    {
        $account = $composition->account;
        $contentIds = [];
        foreach ($composition->attachments as $attachment) {
            if (null !== $attachment->contentId) {
                $contentIds[$attachment->contentId] = self::headerContentId($attachment->contentId);
            }
        }
        $html = $this->html->sanitize($composition->html, $contentIds);

        $email = new Email()
            ->from(new Address($account->getEmailAddress(), $account->getDisplayName()))
            ->to(...self::addresses($composition->to))
            ->cc(...self::addresses($composition->cc))
            ->bcc(...self::addresses($composition->bcc))
            ->subject($composition->subject)
            ->date($composition->sendAt);
        $inline = $files = [];
        foreach ($composition->attachments as $attachment) {
            try {
                $content = $this->blobs->read($account->getOwner()->getId(), $attachment->objectKey);
            } catch (ObjectNotFound $exception) {
                throw new MissingAttachment($attachment->filename, $exception);
            }
            // A type that is not one makes an unusable part (or a broken header); such a file goes out as plain bytes.
            $type = 1 === preg_match(OutgoingAttachment::CONTENT_TYPE, $attachment->contentType) ? $attachment->contentType : 'application/octet-stream';
            $part = new DataPart($content, $attachment->filename, $type);
            $contentId = null === $attachment->contentId ? null : $contentIds[$attachment->contentId];
            // An image goes with the text only while the text refers to it (cid:{contentId}), so mail apps show it in
            // place; one it no longer shows goes out as a file the recipient can see, not as a hidden part.
            if (null === $contentId || !self::refersTo($html, $contentId)) {
                $files[] = $part;
                continue;
            }
            $inline[] = $part->asInline()->setContentId($contentId);
        }
        // Built here rather than by Symfony, which keeps an image with the text only when an img src or a background
        // attribute names it: one an inline style shows would go out as a file.
        $alternatives = [new TextPart($this->html->toText($html), 'utf-8'), new TextPart($html, 'utf-8', 'html')];
        if (null !== $composition->calendar) {
            // As the common clients send it: the calendar object as a third alternative, and the same object as an
            // .ics file for clients that only look at attachments.
            $alternatives[] = new CalendarTextPart($composition->calendar->ical, $composition->calendar->method);
            $files[] = new DataPart($composition->calendar->ical, 'invite.ics', 'application/ics');
        }
        $body = new AlternativePart(...$alternatives);
        if ([] !== $inline) {
            $body = new RelatedPart($body, ...$inline);
        }
        $email->setBody([] === $files ? $body : new MixedPart($body, ...$files));
        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', $messageId);
        $headers->addTextHeader('User-Agent', 'Tresso');

        $original = $composition->inReplyTo;
        if (null !== $original && null !== $original->getMessageIdHeader()) {
            $headers->addIdHeader('In-Reply-To', $original->getMessageIdHeader());
            $headers->addIdHeader('References', $this->references($original));
        }

        return $email;
    }

    private static function domain(Composition $composition): string
    {
        return substr((string) strrchr($composition->account->getEmailAddress(), '@'), 1);
    }

    /**
     * The original's own chain plus the original itself, oldest first.
     *
     * @return list<string>
     */
    private function references(Message $original): array
    {
        /** @var list<string> $chain */
        $chain = $this->entityManager->createQueryBuilder()
            ->select('r.referencedMessageId')
            ->from(MessageReference::class, 'r')
            ->where('r.message = :message')
            ->setParameter('message', $original->getId(), 'uuid')
            ->orderBy('r.id')
            ->getQuery()
            ->getSingleColumnResult();
        $chain[] = (string) $original->getMessageIdHeader();

        return \array_slice(array_values(array_unique($chain)), -self::MAX_REFERENCES);
    }

    /**
     * @param list<array{address: string, name: ?string}> $recipients
     *
     * @return list<Address>
     */
    private static function addresses(array $recipients): array
    {
        return array_map(static fn (array $recipient): Address => new Address($recipient['address'], $recipient['name'] ?? ''), $recipients);
    }
}
