<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Outbox\SendMessageInput;
use App\Entity\OutgoingMessage;
use App\Enum\OutgoingStatus;
use App\Security\Scope;
use App\State\OutgoingMessageProcessor;
use App\State\OutgoingMessageProvider;

/**
 * Mail on its way out. The sync worker of the account sends it and files a copy in the Sent folder; progress (sent,
 * retrying with `lastError`, failed) reaches clients through the delta as `outgoingMessages`.
 */
#[ApiResource(
    shortName: 'OutgoingMessage',
    routePrefix: '/api/v1',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(
            uriTemplate: '/outgoing-messages',
            security: "is_granted('".Scope::ROLE_MAIL_SEND."')",
            input: SendMessageInput::class,
            read: false,
            processor: OutgoingMessageProcessor::class,
        ),
        new Get(
            uriTemplate: '/outgoing-messages/{id}',
            security: "is_granted('".Scope::ROLE_MAIL_READ."')",
            provider: OutgoingMessageProvider::class,
        ),
        // Undo send, or drop a scheduled message. Only before a worker has picked it up.
        new Post(
            uriTemplate: '/outgoing-messages/{id}/cancel',
            status: 200,
            security: "is_granted('".Scope::ROLE_MAIL_SEND."')",
            input: false,
            provider: OutgoingMessageProvider::class,
            processor: OutgoingMessageProcessor::class,
            name: 'outgoing_message_cancel',
        ),
    ],
)]
final readonly class OutgoingMessageResource
{
    /**
     * @param array{to: list<array{address: string, name: ?string}>, cc: list<array{address: string, name: ?string}>, bcc: list<array{address: string, name: ?string}>} $recipients
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $mailAccountId,
        public OutgoingStatus $status,
        public string $subject,
        public array $recipients,
        public \DateTimeImmutable $sendAt,
        public ?\DateTimeImmutable $sentAt,
        public ?string $lastError,
        public ?string $inReplyToMessageId,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromEntity(OutgoingMessage $message): self
    {
        return new self(
            $message->getId()->toRfc4122(),
            $message->getMailAccount()->getId()->toRfc4122(),
            $message->getStatus(),
            $message->getSubject(),
            $message->getRecipients(),
            $message->getSendAt(),
            $message->getSentAt(),
            $message->getLastError(),
            $message->getInReplyTo()?->getId()->toRfc4122(),
            $message->getCreatedAt(),
        );
    }
}
