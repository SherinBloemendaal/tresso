<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TodoPlanState;
use App\Enum\TodoSource;
use App\Repository\TodoRepository;
use App\Sync\Change\TracksChanges;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Something the user has to do, synced to every device. It may come from an email (made by hand or automatically by a
 * smart inbox); one email has at most one todo. The due date and time are wall-clock values in the user's own time
 * zone, like a paper diary: "Friday 17:00" stays Friday 17:00 when the user travels.
 *
 * An open todo with a deadline and a duration can be planned: it then holds time in the user's calendar (instants, from
 * {@see \App\Todo\TodoPlanner}) that counts as busy, so no meeting is booked over it.
 */
#[ORM\Entity(repositoryClass: TodoRepository::class)]
#[ORM\Index(columns: ['owner_id', 'completed_at', 'due_date'])]
#[ORM\Index(columns: ['owner_id', 'change_seq'])]
#[ORM\Index(columns: ['owner_id', 'planned_start'])]
#[ORM\UniqueConstraint(columns: ['message_id'])]
class Todo implements TracksChanges
{
    public const int TITLE_MAX_LENGTH = 500;
    public const int NOTES_MAX_LENGTH = 10000;
    /** A todo takes at most a week. */
    public const int DURATION_MAX_MINUTES = 7 * 24 * 60;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $changeSeq = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: self::TITLE_MAX_LENGTH)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $notes = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    /** Only with a due date; null means some time that day. */
    #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueTime = null;

    /** How long it takes, for planning it in the calendar. */
    #[ORM\Column(nullable: true)]
    private ?int $durationMinutes = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** The time it holds in the calendar, in UTC; null while it is not planned. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $plannedStart = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $plannedEnd = null;

    #[ORM\Column(length: 16, nullable: true, enumType: TodoPlanState::class)]
    private ?TodoPlanState $planState = null;

    /**
     * When the user removed its planned time: automatic planning leaves it without until the todo itself changes (its
     * due date or duration, or it is reopened) or the user plans it.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $planDeclinedAt = null;

    /** The email this todo came from, if any. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Message $message;

    #[ORM\Column(length: 16, enumType: TodoSource::class)]
    private TodoSource $source;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $owner, string $title, TodoSource $source, ?Message $message = null)
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->title = $title;
        $this->source = $source;
        $this->message = $message;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        $this->touch();
    }

    public function getNotes(): string
    {
        return $this->notes;
    }

    public function setNotes(string $notes): void
    {
        $this->notes = $notes;
        $this->touch();
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function getDueTime(): ?\DateTimeImmutable
    {
        return $this->dueTime;
    }

    /** A time needs a date: without one there is nothing to be due on, nor anything to plan before. */
    public function setDue(?\DateTimeImmutable $date, ?\DateTimeImmutable $time): void
    {
        if (null === $date && null !== $time) {
            throw new \InvalidArgumentException('A due time needs a due date.');
        }
        $this->dueDate = $date;
        $this->dueTime = $time;
        $this->planDeclinedAt = null;
        if (null === $date) {
            $this->unplan();
        }
        $this->touch();
    }

    public function getDurationMinutes(): ?int
    {
        return $this->durationMinutes;
    }

    /** Without a duration there is no time to hold in the calendar. */
    public function setDurationMinutes(?int $durationMinutes): void
    {
        $this->durationMinutes = $durationMinutes;
        $this->planDeclinedAt = null;
        if (null === $durationMinutes) {
            $this->unplan();
        }
        $this->touch();
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function isDone(): bool
    {
        return null !== $this->completedAt;
    }

    /**
     * Completing a todo that is done already keeps the moment it was first completed. A done todo holds no time in the
     * calendar.
     */
    public function complete(\DateTimeImmutable $at): void
    {
        if (null !== $this->completedAt) {
            return;
        }
        $this->completedAt = $at;
        $this->unplan();
        $this->touch();
    }

    public function reopen(): void
    {
        if (null === $this->completedAt) {
            return;
        }
        $this->completedAt = null;
        $this->planDeclinedAt = null;
        $this->touch();
    }

    public function getPlannedStart(): ?\DateTimeImmutable
    {
        return $this->plannedStart;
    }

    public function getPlannedEnd(): ?\DateTimeImmutable
    {
        return $this->plannedEnd;
    }

    public function getPlanState(): ?TodoPlanState
    {
        return $this->planState;
    }

    public function isPlanned(): bool
    {
        return null !== $this->plannedStart;
    }

    /** Holds this free time in the calendar. */
    public function planAt(\DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        if (null !== $this->completedAt || null === $this->dueDate || null === $this->durationMinutes) {
            throw new \LogicException('Only an open todo with a deadline and a duration can be planned.');
        }
        if ($end <= $start) {
            throw new \InvalidArgumentException('Planned time must end after it starts.');
        }
        $start = $start->setTimezone(new \DateTimeZone('UTC'));
        $end = $end->setTimezone(new \DateTimeZone('UTC'));
        $this->planDeclinedAt = null;
        if ($start == $this->plannedStart && $end == $this->plannedEnd && TodoPlanState::Planned === $this->planState) {
            return;
        }
        $this->plannedStart = $start;
        $this->plannedEnd = $end;
        $this->planState = TodoPlanState::Planned;
        $this->touch();
    }

    /** The planned time is no longer free and nothing else was: it stays, flagged. */
    public function flagConflict(): void
    {
        if (null === $this->plannedStart) {
            throw new \LogicException('Only planned time can conflict.');
        }
        if (TodoPlanState::Conflict === $this->planState) {
            return;
        }
        $this->planState = TodoPlanState::Conflict;
        $this->touch();
    }

    public function getPlanDeclinedAt(): ?\DateTimeImmutable
    {
        return $this->planDeclinedAt;
    }

    /** The user gives the planned time back: automatic planning leaves the todo alone until it changes. */
    public function declinePlan(\DateTimeImmutable $at): void
    {
        $this->unplan();
        $this->planDeclinedAt = $at;
    }

    public function unplan(): void
    {
        if (null === $this->plannedStart) {
            return;
        }
        $this->plannedStart = null;
        $this->plannedEnd = null;
        $this->planState = null;
        $this->touch();
    }

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    /** The email goes away (its account is removed); the todo stays, without the link. */
    public function detachFromMessage(): void
    {
        $this->message = null;
        $this->touch();
    }

    public function getSource(): TodoSource
    {
        return $this->source;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getChangeOwner(): User
    {
        return $this->owner;
    }

    public static function clientFields(): ?array
    {
        return null;
    }

    public static function changeType(): string
    {
        return 'todo';
    }

    public function getChangeSeq(): int
    {
        return $this->changeSeq;
    }

    public function setChangeSeq(int $changeSeq): void
    {
        $this->changeSeq = $changeSeq;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
