<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\NotificationKind;
use App\Repository\ScheduledNotificationRepository;
use App\Sync\Change\TracksChanges;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One notification for one occurrence of an event: planned with its fire time, then delivered (and possibly dismissed
 * or snoozed). Planned rows change freely while the event changes; delivered ones reach clients through the delta, so
 * the in-app list and the desktop app see them and a dismissal on one device clears the others.
 */
#[ORM\Entity(repositoryClass: ScheduledNotificationRepository::class)]
#[ORM\UniqueConstraint(columns: ['event_id', 'occurrence_key', 'kind', 'slot'])]
#[ORM\Index(columns: ['delivered_at', 'fire_at'])]
#[ORM\Index(columns: ['user_id', 'change_seq'])]
class ScheduledNotification implements TracksChanges
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $changeSeq = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Null once the event is gone; delivered notifications stay in the history. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CalendarEvent $event;

    /** The occurrence ('' for a single event), as a recurrence key. */
    #[ORM\Column(length: 16)]
    private string $occurrenceKey;

    #[ORM\Column(length: 16, enumType: NotificationKind::class)]
    private NotificationKind $kind;

    /** Which reminder of the event or rule this is (valarm:0, rule:1, ...). */
    #[ORM\Column(length: 32)]
    private string $slot;

    #[ORM\Column]
    private \DateTimeImmutable $fireAt;

    #[ORM\Column]
    private \DateTimeImmutable $occurrenceStartsAt;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $channels;

    /** Delivered without sound or banner (quiet hours, focus). */
    #[ORM\Column]
    private bool $silent = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    /** Each snooze starts a new round of deliveries. */
    #[ORM\Column]
    private int $round = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dismissedAt = null;

    /** Leave now: the travel minutes the phone's last estimate gave; null until one came. */
    #[ORM\Column(nullable: true)]
    private ?int $estimatedMinutes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string> $channels
     */
    public function __construct(User $user, CalendarEvent $event, string $occurrenceKey, NotificationKind $kind, string $slot, \DateTimeImmutable $fireAt, \DateTimeImmutable $occurrenceStartsAt, string $title, string $body, array $channels)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->event = $event;
        $this->occurrenceKey = $occurrenceKey;
        $this->kind = $kind;
        $this->slot = $slot;
        $this->fireAt = self::utc($fireAt);
        $this->occurrenceStartsAt = self::utc($occurrenceStartsAt);
        $this->title = $title;
        $this->body = $body;
        $this->channels = $channels;
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Takes over a new plan for a notification not delivered yet. Returns whether the fire time moved.
     *
     * @param list<string> $channels
     */
    public function replan(\DateTimeImmutable $fireAt, \DateTimeImmutable $occurrenceStartsAt, string $title, string $body, array $channels): bool
    {
        $moved = $fireAt != $this->fireAt;
        if ($moved) {
            $this->fireAt = self::utc($fireAt);
        }
        if ($occurrenceStartsAt != $this->occurrenceStartsAt) {
            $this->occurrenceStartsAt = self::utc($occurrenceStartsAt);
        }
        $this->title = $title;
        $this->body = $body;
        $this->channels = $channels;

        return $moved;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEvent(): ?CalendarEvent
    {
        return $this->event;
    }

    public function getOccurrenceKey(): string
    {
        return $this->occurrenceKey;
    }

    public function getKind(): NotificationKind
    {
        return $this->kind;
    }

    public function getSlot(): string
    {
        return $this->slot;
    }

    public function getFireAt(): \DateTimeImmutable
    {
        return $this->fireAt;
    }

    public function getEstimatedMinutes(): ?int
    {
        return $this->estimatedMinutes;
    }

    /**
     * Leave now: a travel estimate came; the reminder moves to the moment to leave.
     */
    public function estimate(int $minutes, \DateTimeImmutable $leaveAt, string $body): void
    {
        $this->estimatedMinutes = $minutes;
        $this->fireAt = self::utc($leaveAt);
        $this->body = $body;
    }

    /** Quiet hours move a notification to their end. */
    public function postpone(\DateTimeImmutable $fireAt): void
    {
        $this->fireAt = self::utc($fireAt);
    }

    public function getOccurrenceStartsAt(): \DateTimeImmutable
    {
        return $this->occurrenceStartsAt;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /** @return list<string> */
    public function getChannels(): array
    {
        return $this->channels;
    }

    public function isSilent(): bool
    {
        return $this->silent;
    }

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function isDelivered(): bool
    {
        return null !== $this->deliveredAt;
    }

    public function markDelivered(\DateTimeImmutable $at, bool $silent): void
    {
        $this->deliveredAt = self::utc($at);
        $this->silent = $silent;
    }

    public function getRound(): int
    {
        return $this->round;
    }

    public function snooze(\DateTimeImmutable $until): void
    {
        $this->fireAt = self::utc($until);
        $this->deliveredAt = null;
        $this->dismissedAt = null;
        ++$this->round;
    }

    public function getDismissedAt(): ?\DateTimeImmutable
    {
        return $this->dismissedAt;
    }

    public function dismiss(\DateTimeImmutable $at): void
    {
        $this->dismissedAt = self::utc($at);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getChangeOwner(): User
    {
        return $this->user;
    }

    public static function clientFields(): ?array
    {
        // Clients see a notification once it is delivered; planning changes are no change for them.
        return ['deliveredAt', 'dismissedAt', 'silent', 'round'];
    }

    public static function changeType(): string
    {
        return 'notification';
    }

    public function getChangeSeq(): int
    {
        return $this->changeSeq;
    }

    public function setChangeSeq(int $changeSeq): void
    {
        $this->changeSeq = $changeSeq;
    }

    /** Date-time columns keep the wall clock only, so every moment is stored in UTC. */
    private static function utc(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'));
    }
}
