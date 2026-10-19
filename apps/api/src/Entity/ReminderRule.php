<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RuleRole;
use App\Repository\ReminderRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Which reminders an event gets when it has none of its own: by calendar and by kind of event. The most specific
 * rule that matches wins; ties go to the lowest position.
 *
 * @phpstan-type RuleReminder array{minutesBefore: ?int, dayBeforeAt: ?string, channels: list<string>}
 */
#[ORM\Entity(repositoryClass: ReminderRuleRepository::class)]
#[ORM\Index(columns: ['user_id', 'position'])]
class ReminderRule
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 120)]
    private string $name;

    /** Only events of this calendar; every calendar when null. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Calendar $calendar = null;

    /** true: only all-day events, false: only timed events, null: both. */
    #[ORM\Column(nullable: true)]
    private ?bool $allDay = null;

    #[ORM\Column(length: 16, enumType: RuleRole::class)]
    private RuleRole $role = RuleRole::Any;

    /** true: only events with other people, false: only events without, null: both. */
    #[ORM\Column(nullable: true)]
    private ?bool $withAttendees = null;

    #[ORM\Column(nullable: true)]
    private ?bool $withConference = null;

    #[ORM\Column(nullable: true)]
    private ?bool $withLocation = null;

    /** @var list<RuleReminder> */
    #[ORM\Column(type: Types::JSON)]
    private array $reminders = [];

    /** Remind when it is time to leave, for events with a place. */
    #[ORM\Column]
    private bool $leaveNow = false;

    /** Minutes before a meeting to send its brief; none when null. */
    #[ORM\Column(nullable: true)]
    private ?int $briefMinutes = null;

    /** Nudge for notes and follow-up after meetings. */
    #[ORM\Column]
    private bool $followUp = false;

    #[ORM\Column]
    private int $position = 0;

    public function __construct(User $user, string $name)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->name = $name;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getCalendar(): ?Calendar
    {
        return $this->calendar;
    }

    public function getAllDay(): ?bool
    {
        return $this->allDay;
    }

    public function getRole(): RuleRole
    {
        return $this->role;
    }

    public function getWithAttendees(): ?bool
    {
        return $this->withAttendees;
    }

    public function getWithConference(): ?bool
    {
        return $this->withConference;
    }

    public function getWithLocation(): ?bool
    {
        return $this->withLocation;
    }

    public function match(?Calendar $calendar, ?bool $allDay, RuleRole $role, ?bool $withAttendees, ?bool $withConference, ?bool $withLocation): void
    {
        $this->calendar = $calendar;
        $this->allDay = $allDay;
        $this->role = $role;
        $this->withAttendees = $withAttendees;
        $this->withConference = $withConference;
        $this->withLocation = $withLocation;
    }

    /** How many conditions the rule sets: more is more specific. */
    public function specificity(): int
    {
        return \count(array_filter([
            null !== $this->calendar,
            null !== $this->allDay,
            RuleRole::Any !== $this->role,
            null !== $this->withAttendees,
            null !== $this->withConference,
            null !== $this->withLocation,
        ]));
    }

    /** @return list<RuleReminder> */
    public function getReminders(): array
    {
        return $this->reminders;
    }

    /**
     * @param list<RuleReminder> $reminders
     */
    public function setReminders(array $reminders): void
    {
        $this->reminders = $reminders;
    }

    public function isLeaveNow(): bool
    {
        return $this->leaveNow;
    }

    public function setLeaveNow(bool $leaveNow): void
    {
        $this->leaveNow = $leaveNow;
    }

    public function getBriefMinutes(): ?int
    {
        return $this->briefMinutes;
    }

    public function setBriefMinutes(?int $briefMinutes): void
    {
        $this->briefMinutes = $briefMinutes;
    }

    public function isFollowUp(): bool
    {
        return $this->followUp;
    }

    public function setFollowUp(bool $followUp): void
    {
        $this->followUp = $followUp;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
}
