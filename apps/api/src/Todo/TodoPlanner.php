<?php

declare(strict_types=1);

namespace App\Todo;

use App\Calendar\Assistant\BusyTimeSource;
use App\Calendar\Assistant\InvalidMeetingTimeQuery;
use App\Calendar\Assistant\MeetingTimeFinder;
use App\Calendar\Assistant\MeetingTimeQuery;
use App\Calendar\Assistant\NoSlotReason;
use App\Calendar\Assistant\Slot;
use App\Calendar\TooManyOccurrences;
use App\Classification\UserLocks;
use App\Entity\Todo;
use App\Entity\User;
use App\Enum\TodoPlanState;
use App\Repository\NotificationPreferencesRepository;
use App\Repository\SchedulingPreferencesRepository;
use App\Repository\TodoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Gives todos time in the calendar and keeps that time free. A todo is planned with the meeting time finder: the
 * earliest free stretch of its duration before its deadline, within working hours, around focus blocks, buffers and
 * travel time. Planned time counts as busy ({@see TodoBusyTimes}).
 *
 * Planned time is checked again when the calendar changes, and when the todo's deadline or duration changes: time that
 * no longer fits (something else is busy then, it ends after the deadline, or it is no longer as long as the todo
 * takes) moves to the earliest free time; when there is none it stays and is flagged as a conflict. Time that has
 * started is left alone. The deadline is the due date and time in the user's zone, or the end of the due date.
 *
 * Writes take the user's todo lock, so a re-check after a calendar change and a change by the user take turns.
 */
final readonly class TodoPlanner
{
    /**
     * @param iterable<BusyTimeSource> $busySources
     */
    public function __construct(
        private MeetingTimeFinder $finder,
        private NotificationPreferencesRepository $notificationPreferences,
        private SchedulingPreferencesRepository $schedulingPreferences,
        private TodoRepository $todos,
        private UserLocks $locks,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        #[AutowireIterator(BusyTimeSource::TAG)]
        private iterable $busySources,
    ) {
    }

    /**
     * Plans the todo (again) at the earliest free time before its deadline, whatever time it had.
     *
     * @throws TodoNotPlannable
     */
    public function plan(Todo $todo, \DateTimeImmutable $now): void
    {
        $this->locks->todos($todo->getOwner()->getId());
        $slot = $this->find($todo, $this->zone($todo->getOwner()), $now);
        $todo->planAt($slot->start, $slot->end);
    }

    /**
     * Follows a change the user made to a todo. Planned time is checked again when the deadline or the duration changed;
     * a todo without time gets some when the user plans todos automatically and it was made, reopened, or got a new
     * deadline or duration ($scheduleChanged). Not finding free time then leaves it without.
     */
    public function follow(Todo $todo, bool $scheduleChanged, \DateTimeImmutable $now): void
    {
        if (!$scheduleChanged || $todo->isDone()) {
            return;
        }
        $owner = $todo->getOwner();
        $this->locks->todos($owner->getId());
        $zone = $this->zone($owner);
        try {
            if ($todo->isPlanned()) {
                $this->keepOrMove($todo, $zone, $now);

                return;
            }
            if ($this->schedulingPreferences->forUser($owner)->plansTodos()) {
                $this->tryPlan($todo, $zone, $now);
            }
        } catch (TooManyOccurrences $e) {
            // The calendar is too full to read: the todo stays as it is, a later change checks it again.
            $this->logger->warning('Todo planning skipped', ['todo' => $todo->getId()->toRfc4122(), 'error' => $e->getMessage()]);
        }
    }

    /**
     * Checks the user's planned time that has not started, earliest deadline first, after their calendar changed; with
     * $planOpen also plans the open todos that can be and have no time yet (todo planning was just switched on).
     */
    public function replan(User $user, \DateTimeImmutable $now, bool $planOpen = false): void
    {
        $this->locks->todos($user->getId());
        $zone = $this->zone($user);
        foreach ($this->todos->findPlannedFrom($user, $now) as $todo) {
            $this->guarded($todo, fn () => $this->keepOrMove($todo, $zone, $now));
        }
        if (!$planOpen) {
            return;
        }
        foreach ($this->todos->findPlannable($user) as $todo) {
            $this->guarded($todo, fn () => $this->tryPlan($todo, $zone, $now));
        }
    }

    /**
     * The moment a todo is due: its due date and time in $zone, or the end of its due date.
     */
    public static function deadline(Todo $todo, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        $date = $todo->getDueDate();
        if (null === $date) {
            return null;
        }
        $time = $todo->getDueTime();
        if (null === $time) {
            return new \DateTimeImmutable($date->format('Y-m-d').' 00:00', $zone)->modify('+1 day');
        }

        return new \DateTimeImmutable($date->format('Y-m-d').' '.$time->format('H:i'), $zone);
    }

    /**
     * Runs one todo's planning and saves it, so the next todo sees its time as busy. A calendar too full to read
     * leaves the todo as it is.
     *
     * @param \Closure(): void $plan
     */
    private function guarded(Todo $todo, \Closure $plan): void
    {
        try {
            $plan();
        } catch (TooManyOccurrences $e) {
            $this->logger->warning('Todo planning skipped', ['todo' => $todo->getId()->toRfc4122(), 'error' => $e->getMessage()]);

            return;
        }
        $this->entityManager->flush();
    }

    /**
     * Keeps planned time that still fits, moves time that does not to the earliest free time, and flags it when there
     * is none. Time that has started is left alone.
     */
    private function keepOrMove(Todo $todo, \DateTimeZone $zone, \DateTimeImmutable $now): void
    {
        $start = $todo->getPlannedStart();
        $end = $todo->getPlannedEnd();
        if (null === $start || null === $end || $start <= $now) {
            return;
        }
        if ($this->fits($todo, $start, $end, $zone)) {
            if (TodoPlanState::Conflict === $todo->getPlanState()) {
                $todo->planAt($start, $end);
            }

            return;
        }
        try {
            $slot = $this->find($todo, $zone, $now);
        } catch (TodoNotPlannable) {
            $todo->flagConflict();

            return;
        }
        $todo->planAt($slot->start, $slot->end);
    }

    private function tryPlan(Todo $todo, \DateTimeZone $zone, \DateTimeImmutable $now): void
    {
        try {
            $slot = $this->find($todo, $zone, $now);
        } catch (TodoNotPlannable) {
            return;
        }
        $todo->planAt($slot->start, $slot->end);
    }

    /**
     * Whether the planned time is still right for the todo: as long as it takes, over by its deadline, and with
     * nothing else busy then.
     */
    private function fits(Todo $todo, \DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeZone $zone): bool
    {
        $deadline = self::deadline($todo, $zone);
        $minutes = $todo->getDurationMinutes();
        if (null === $deadline || null === $minutes || $end > $deadline || $end->getTimestamp() - $start->getTimestamp() !== $minutes * 60) {
            return false;
        }
        $own = $todo->getId()->toRfc4122();
        foreach ($this->busySources as $source) {
            foreach ($source->busyTimes($todo->getOwner(), $start, $end, $zone, null) as $time) {
                if ($time->overlaps($start, $end) && !\in_array($own, $time->references, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @throws TodoNotPlannable
     * @throws TooManyOccurrences only when the finder could not read the calendar; {@see plan()} turns it into a reason
     */
    private function find(Todo $todo, \DateTimeZone $zone, \DateTimeImmutable $now): Slot
    {
        if ($todo->isDone()) {
            throw TodoNotPlannable::done();
        }
        $deadline = self::deadline($todo, $zone) ?? throw TodoNotPlannable::withoutDeadline();
        $minutes = $todo->getDurationMinutes() ?? throw TodoNotPlannable::withoutDuration();
        if ($minutes > MeetingTimeQuery::MAX_DURATION_MINUTES) {
            throw TodoNotPlannable::tooLong();
        }
        if ($deadline <= $now) {
            throw TodoNotPlannable::deadlinePassed();
        }

        try {
            $query = MeetingTimeQuery::beforeDeadline($now, $deadline, max($minutes, MeetingTimeQuery::MIN_DURATION_MINUTES), [$todo->getId()->toRfc4122()], $zone);
        } catch (InvalidMeetingTimeQuery) {
            throw TodoNotPlannable::deadlinePassed();
        }
        $times = $this->finder->find($todo->getOwner(), $query, $now);
        $slot = $times->slots[0] ?? null;
        if (null !== $slot) {
            // A todo shorter than the finder's shortest search holds only its own length.
            return $minutes < MeetingTimeQuery::MIN_DURATION_MINUTES ? new Slot($slot->start, $slot->start->modify(\sprintf('+%d minutes', $minutes)), $slot->freeFrom, $slot->freeUntil) : $slot;
        }

        throw match ($times->noSlotReason) {
            NoSlotReason::NoWorkingHours => TodoNotPlannable::noWorkingHours(),
            NoSlotReason::WindowPassed => TodoNotPlannable::deadlinePassed(),
            NoSlotReason::NoFreeTime, null => TodoNotPlannable::noFreeTime(),
        };
    }

    private function zone(User $user): \DateTimeZone
    {
        return new \DateTimeZone($this->notificationPreferences->forUser($user)->getTimeZone());
    }
}
