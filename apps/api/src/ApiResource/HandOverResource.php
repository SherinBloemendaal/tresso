<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\HandOver\CreateHandOver;
use App\ApiResource\Input\HandOver\NudgeHandOver;
use App\ApiResource\Input\HandOver\UpdateHandOver;
use App\Entity\HandOver;
use App\Enum\HandOverState;
use App\Security\Scope;
use App\State\HandOverProcessor;
use App\State\HandOverProvider;

/**
 * A conversation handed over: forwarded to someone with a note and a due date, and listed in "Waiting on" until they
 * reply in it (detected when the reply arrives) or the user marks it done. Once the due date passes without a reply,
 * `overdueAt` is set and clients tell the user, offering a nudge. Clients keep their copy through the delta
 * (`handOvers`, tombstone type `handOver`).
 */
#[ApiResource(
    shortName: 'HandOver',
    routePrefix: '/api/v1',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/hand-overs',
            paginationEnabled: false,
            security: "is_granted('".Scope::ROLE_MAIL_READ."')",
            provider: HandOverProvider::class,
        ),
        new Get(
            uriTemplate: '/hand-overs/{id}',
            security: "is_granted('".Scope::ROLE_MAIL_READ."')",
            provider: HandOverProvider::class,
        ),
        new Post(
            uriTemplate: '/hand-overs',
            security: "is_granted('".Scope::ROLE_MAIL_WRITE."')",
            input: CreateHandOver::class,
            read: false,
            processor: HandOverProcessor::class,
        ),
        new Patch(
            uriTemplate: '/hand-overs/{id}',
            security: "is_granted('".Scope::ROLE_MAIL_WRITE."')",
            input: UpdateHandOver::class,
            provider: HandOverProvider::class,
            processor: HandOverProcessor::class,
        ),
        // Withdraws it, for example when its forward was undone. The forward itself is not touched.
        new Delete(
            uriTemplate: '/hand-overs/{id}',
            security: "is_granted('".Scope::ROLE_MAIL_WRITE."')",
            provider: HandOverProvider::class,
            processor: HandOverProcessor::class,
        ),
        new Post(
            uriTemplate: '/hand-overs/{id}/nudge',
            status: 200,
            security: "is_granted('".Scope::ROLE_MAIL_SEND."')",
            input: NudgeHandOver::class,
            provider: HandOverProvider::class,
            processor: HandOverProcessor::class,
            name: 'hand_over_nudge',
        ),
    ],
)]
final readonly class HandOverResource
{
    /**
     * @param array{address: string, name: ?string} $delegate
     * @param list<string>                          $notifiedChannels
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $mailAccountId,
        /** The message handed over; null once it is gone. */
        public ?string $messageId,
        /** The conversation of that message, or the one it was handed over in once the message is gone. */
        public ?string $threadId,
        public string $subject,
        public array $delegate,
        public string $note,
        public \DateTimeImmutable $dueAt,
        public HandOverState $state,
        /** The outgoing message that forwarded it. */
        public ?string $forwardId,
        public ?\DateTimeImmutable $overdueAt,
        /** When the overdue notice was delivered, on these channels (none: the user chose none for hand-overs). */
        public ?\DateTimeImmutable $notifiedAt,
        public array $notifiedChannels,
        public bool $notifiedSilently,
        public ?\DateTimeImmutable $nudgedAt,
        /** The latest nudge, an outgoing message; cancel it to undo the nudge. */
        public ?string $lastNudgeId,
        /** The delegate's reply that closed it. */
        public ?string $replyMessageId,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $closedAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromEntity(HandOver $handOver): self
    {
        return new self(
            $handOver->getId()->toRfc4122(),
            $handOver->getMailAccount()->getId()->toRfc4122(),
            $handOver->getMessage()?->getId()->toRfc4122(),
            ($handOver->getMessage()?->getThread() ?? $handOver->getThread())?->getId()->toRfc4122(),
            $handOver->getSubject(),
            ['address' => $handOver->getDelegateAddress(), 'name' => $handOver->getDelegateName()],
            $handOver->getNote(),
            $handOver->getDueAt(),
            $handOver->getState(),
            $handOver->getForward()?->getId()->toRfc4122(),
            $handOver->getOverdueAt(),
            $handOver->getNotifiedAt(),
            $handOver->getNotifiedChannels(),
            $handOver->isNotifiedSilently(),
            $handOver->getNudgedAt(),
            $handOver->getLastNudge()?->getId()->toRfc4122(),
            $handOver->getReply()?->getId()->toRfc4122(),
            $handOver->getCreatedAt(),
            $handOver->getClosedAt(),
            $handOver->getUpdatedAt(),
        );
    }
}
