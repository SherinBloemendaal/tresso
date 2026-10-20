<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Unsubscribe\UnsubscribeInput;
use App\Entity\Unsubscription;
use App\Security\Scope;
use App\State\UnsubscriptionProcessor;

/**
 * Unsubscribes the account that received a message from its sender, with one click: an RFC 8058 one-click POST, made
 * by the sync worker, when the message offers one, otherwise a mail to its `mailto:` unsubscribe address, sent through
 * the outbox. Other unsubscribe links are never followed. The request's outcome reaches clients in the delta
 * (`unsubscriptions`); asking again while one is pending or done answers that one, after a failure it tries again.
 * Mail in Spam is refused (422): answering it would tell the sender the address is read.
 */
#[ApiResource(
    shortName: 'Unsubscription',
    routePrefix: '/api/v1',
    operations: [
        new Post(
            uriTemplate: '/unsubscriptions',
            status: 202,
            security: "is_granted('".Scope::ROLE_MAIL_SEND."')",
            input: UnsubscribeInput::class,
            read: false,
            processor: UnsubscriptionProcessor::class,
            normalizationContext: ['skip_null_values' => false],
        ),
    ],
)]
final readonly class UnsubscriptionResource
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $mailAccountId,
        /** The lower-cased From address the account no longer wants mail from. */
        public string $sender,
        /** `oneClick` or `mailto`. */
        public string $method,
        /** `pending`, `done` or `failed`. */
        public string $status,
        public ?string $error,
        public \DateTimeImmutable $requestedAt,
        public ?\DateTimeImmutable $completedAt,
    ) {
    }

    public static function fromEntity(Unsubscription $unsubscription): self
    {
        return new self(
            $unsubscription->getId()->toRfc4122(),
            $unsubscription->getMailAccount()->getId()->toRfc4122(),
            $unsubscription->getSender(),
            $unsubscription->getMethod()->value,
            $unsubscription->getStatus()->value,
            $unsubscription->getError(),
            $unsubscription->getRequestedAt(),
            $unsubscription->getCompletedAt(),
        );
    }
}
