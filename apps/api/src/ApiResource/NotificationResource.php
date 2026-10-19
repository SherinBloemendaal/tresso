<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Notification\SnoozeInput;
use App\Entity\ScheduledNotification;
use App\Enum\NotificationKind;
use App\Security\Scope;
use App\State\NotificationProcessor;
use App\State\NotificationProvider;

/**
 * A notification of the user: delivered in the last week, or planned for the coming days. Dismissing one clears it
 * on every device; snoozing delivers it again later.
 */
#[ApiResource(
    shortName: 'Notification',
    routePrefix: '/api/v1',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(uriTemplate: '/notifications', paginationEnabled: false, security: "is_granted('".Scope::ROLE_CALENDAR_READ."')", provider: NotificationProvider::class),
        new Post(uriTemplate: '/notifications/{id}/dismiss', status: 200, security: "is_granted('".Scope::ROLE_CALENDAR_WRITE."')", input: false, provider: NotificationProvider::class, processor: NotificationProcessor::class, name: 'notification_dismiss'),
        new Post(uriTemplate: '/notifications/{id}/snooze', status: 200, security: "is_granted('".Scope::ROLE_CALENDAR_WRITE."')", input: SnoozeInput::class, provider: NotificationProvider::class, processor: NotificationProcessor::class, name: 'notification_snooze'),
    ],
)]
final readonly class NotificationResource
{
    /**
     * @param list<string> $channels
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public NotificationKind $kind,
        public ?string $eventId,
        public ?string $recurrenceId,
        public string $title,
        public string $body,
        public array $channels,
        public \DateTimeImmutable $fireAt,
        public \DateTimeImmutable $occurrenceStartsAt,
        public ?\DateTimeImmutable $deliveredAt,
        public ?\DateTimeImmutable $dismissedAt,
        public bool $silent,
    ) {
    }

    public static function fromEntity(ScheduledNotification $notification): self
    {
        return new self(
            $notification->getId()->toRfc4122(),
            $notification->getKind(),
            $notification->getEvent()?->getId()->toRfc4122(),
            '' === $notification->getOccurrenceKey() ? null : $notification->getOccurrenceKey(),
            $notification->getTitle(),
            $notification->getBody(),
            $notification->getChannels(),
            $notification->getFireAt(),
            $notification->getOccurrenceStartsAt(),
            $notification->getDeliveredAt(),
            $notification->getDismissedAt(),
            $notification->isSilent(),
        );
    }
}
