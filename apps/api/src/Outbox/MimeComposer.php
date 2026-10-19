<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Entity\Message;
use App\Entity\MessageReference;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

/**
 * Turns a {@see Composition} into the RFC 5322 message that is sent and filed in Sent. Replies carry In-Reply-To and
 * References so every client threads them. Bcc recipients are only on the envelope, never in the message.
 */
final readonly class MimeComposer
{
    /** Clients and servers cope with long chains, but there is no use in sending hundreds of ids. */
    private const int MAX_REFERENCES = 30;

    public function __construct(
        private OutgoingHtmlSanitizer $html,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function compose(Composition $composition): ComposedMessage
    {
        $account = $composition->account;
        $domain = substr((string) strrchr($account->getEmailAddress(), '@'), 1);
        $messageId = Uuid::v7()->toRfc4122().'@'.$domain;
        $html = $this->html->sanitize($composition->html);

        $email = new Email()
            ->from(new Address($account->getEmailAddress(), $account->getDisplayName()))
            ->to(...self::addresses($composition->to))
            ->cc(...self::addresses($composition->cc))
            ->bcc(...self::addresses($composition->bcc))
            ->subject($composition->subject)
            ->date($composition->sendAt)
            ->text($this->html->toText($html), 'utf-8')
            ->html($html, 'utf-8');
        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', $messageId);
        $headers->addTextHeader('User-Agent', 'Tresso');

        $original = $composition->inReplyTo;
        if (null !== $original && null !== $original->getMessageIdHeader()) {
            $headers->addIdHeader('In-Reply-To', $original->getMessageIdHeader());
            $headers->addIdHeader('References', $this->references($original));
        }

        // toString() renders the prepared headers, which leave Bcc out.
        $raw = $email->toString();
        if (1 === preg_match('/^Bcc:/mi', substr($raw, 0, (int) strpos($raw, "\r\n\r\n")))) {
            throw new \LogicException('A Bcc header ended up in the message.');
        }

        return new ComposedMessage($raw, $messageId);
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
