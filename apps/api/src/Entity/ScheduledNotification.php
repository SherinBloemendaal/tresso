<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\NotificationKind;
use App\Repository\ScheduledNotificationRepository;
use App\Sync\Change\SyncedOnlyWhenSeen;
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
class ScheduledNotification implements SyncedOnlyWhenSeen
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

    /**
     * The occurrence's start as the event writes it (a date, a wall clock, or a UTC instant): unlike
     * occurrenceStartsAt it does not change with the user's zone, so it tells whether the occurrence really moved.
     */
    #[ORM\Column(length: 16, options: ['default' => ''])]
    private string $occurrenceStart;

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

    /** Each snooze starts a new round of deliveries, and so does the occurrence moving after a delivery. */
    #[ORM\Column]
    private int $round = 0;

    /** The round waiting for delivery was started by a snooze (not by the occurrence moving). */
    #[ORM\Column(options: ['default' => false])]
    private bool $snoozed = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dismissedAt = null;

    /**
     * A fire time the user or the delivery rules chose (a snooze, the end of quiet hours or of a focus block); a
     * replan never moves the notification before it.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $heldUntil = null;

    /** Leave now: the travel minutes the phone's last estimate gave; null until one came. */
    #[ORM\Column(nullable: true)]
    private ?int $estimatedMinutes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string> $channels
     */
    public function __construct(User $user, CalendarEvent $event, string $occurrenceKey, NotificationKind $kind, string $slot, \DateTimeImmutable $fireAt, \DateTimeImmutable $occurrenceStartsAt, string $occurrenceStart, string $title, string $body, array $channels)
    {
        $this->occurrenceStart = $occurrenceStart;
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
     * Takes over a new plan for a notification not delivered yet, but never before a time it is held until. Returns
     * whether the fire time moved.
     *
     * @param list<string> $channels
     */
    public function replan(\DateTimeImmutable $fireAt, \DateTimeImmutable $occurrenceStartsAt, string $occurrenceStart, string $title, string $body, array $channels): bool
    {
        $this->occurrenceStart = $occurrenceStart;
        if (null !== $this->heldUntil && $this->heldUntil > $fireAt) {
            $fireAt = $this->heldUntil;
        }
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
        // The estimate decides now, not the last time the phone was asked.
        $this->heldUntil = null;
        $this->body = $body;
    }

    /** Quiet hours, working hours or a focus block move a notification to their end; a replan keeps it there. */
    public function postpone(\DateTimeImmutable $fireAt): void
    {
        $this->fireAt = self::utc($fireAt);
        $this->heldUntil = $this->fireAt;
    }

    public function getHeldUntil(): ?\DateTimeImmutable
    {
        return $this->heldUntil;
    }

    /** The time it was held until no longer makes sense (the event moved before it); a replan decides again. */
    public function releaseHold(): void
    {
        $this->heldUntil = null;
    }

    /**
     * The occurrence now takes its fields from another row (an override, or one for this and future occurrences,
     * appeared or went): the notification goes along, with its snooze, hold and deliveries.
     */
    public function moveTo(CalendarEvent $event): void
    {
        $this->event = $event;
    }

    public function getOccurrenceStart(): string
    {
        return $this->occurrenceStart;
    }

    public function isSnoozed(): bool
    {
        return $this->snoozed;
    }

    /**
     * The occurrence moved after this was delivered: a new round with the new plan, so the devices show it again.
     *
     * @param list<string> $channels
     */
    public function restart(\DateTimeImmutable $fireAt, \DateTimeImmutable $occurrenceStartsAt, string $occurrenceStart, string $title, string $body, array $channels): void
    {
        ++$this->round;
        $this->snoozed = false;
        $this->occurrenceStart = $occurrenceStart;
        $this->deliveredAt = null;
        $this->dismissedAt = null;
        $this->silent = false;
        $this->heldUntil = null;
        $this->estimatedMinutes = null;
        $this->fireAt = self::utc($fireAt);
        $this->occurrenceStartsAt = self::utc($occurrenceStartsAt);
        $this->title = $title;
        $this->body = $body;
        $this->channels = $channels;
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
        $this->snoozed = false;
    }

    public function getRound(): int
    {
        return $this->round;
    }

    public function snooze(\DateTimeImmutable $until): void
    {
        $this->fireAt = self::utc($until);
        $this->heldUntil = $this->fireAt;
        $this->deliveredAt = null;
        $this->dismissedAt = null;
        $this->snoozed = true;
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

    /** Clients see a notification once a round of it reached a device (delivered, or snoozed after that). */
    public function isSeenByClients(): bool
    {
        return null !== $this->deliveredAt || $this->round > 0;
    }

    public function getChangeOwner(): User
    {
        return $this->user;
    }

    public static function clientFields(): ?array
    {
        // Clients see a notification once it is delivered (or snoozed): the time a snooze comes again, moved by quiet
        // hours too, is a change for them. The delta leaves out notifications that never reached a device.
        return ['deliveredAt', 'dismissedAt', 'silent', 'round', 'snoozed', 'fireAt'];
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
