<?php

declare(strict_types=1);

namespace App\Notification;

use App\Calendar\ICalendar\CalendarDocument;
use App\Calendar\ICalendar\InvalidCalendarData;
use App\Calendar\ICalendar\Occurrences;
use App\Calendar\ICalendar\RecurrenceKey;
use App\Calendar\OccurrenceExpander;
use App\Calendar\SelfAddresses;
use App\Entity\CalendarEvent;
use App\Entity\CalendarObject;
use App\Entity\NotificationPreferences;
use App\Entity\ReminderRule;
use App\Entity\ScheduledNotification;
use App\Entity\User;
use App\Entity\UserLocation;
use App\Enum\EventStatus;
use App\Enum\NotificationKind;
use App\Enum\ParticipationStatus;
use App\Realtime\AfterCommitDispatcher;
use App\Repository\CalendarEventRepository;
use App\Repository\NotificationPreferencesRepository;
use App\Repository\ScheduledNotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Recur\MaxInstancesExceededException;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Turns events into planned notifications for the occurrences of the coming days: the event's own reminders (or the
 * user's default rule when it has none), leave-now, the brief before a meeting and the follow-up after it. Plans
 * are recomputed whenever an event or the user's settings change; a delivered notification is never planned again.
 * Every new or moved plan is announced as a delayed message for its fire time. Runs inside the caller's transaction.
 */
final readonly class NotificationPlanner
{
    public const string HORIZON = 'P8D';
    /** Leave-now with an estimate is first looked at this long before the start, then moved to the right time. */
    public const int LEAVE_CHECK_MINUTES = 120;
    public const int LEAVE_MARGIN_MINUTES = 5;
    /** Plans this far in the past are left out: too late to matter. */
    private const string TOO_LATE = '-2 minutes';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CalendarEventRepository $events,
        private ScheduledNotificationRepository $notifications,
        private NotificationPreferencesRepository $preferences,
        private ReminderRules $rules,
        private SelfAddresses $selfAddresses,
        private AfterCommitDispatcher $afterCommit,
        private LoggerInterface $logger,
    ) {
    }

    public function planUser(User $user, \DateTimeImmutable $now): void
    {
        $preferences = $this->preferences->forUser($user);
        $until = $now->add(new \DateInterval(self::HORIZON));
        $context = $this->context($user, $preferences);

        foreach ($this->events->objectsInRange($user, [], $now->modify('-1 day'), $until) as $object) {
            $this->planWithin($object, $now, $until, $context);
        }
        foreach ($this->notifications->findOrphans($user) as $orphan) {
            $this->entityManager->remove($orphan);
        }
        $preferences->setPlannedUntil($until);
        $this->entityManager->flush();
    }

    public function planObject(CalendarObject $object, \DateTimeImmutable $now): void
    {
        $user = $object->getCalendar()->getOwner();
        $preferences = $this->preferences->forUser($user);
        $until = max($preferences->getPlannedUntil() ?? $now, $now->add(new \DateInterval(self::HORIZON)));
        $this->planWithin($object, $now, $until, $this->context($user, $preferences));
        $this->entityManager->flush();
    }

    public function removeOrphans(User $user): void
    {
        foreach ($this->notifications->findOrphans($user) as $orphan) {
            $this->entityManager->remove($orphan);
        }
        $this->entityManager->flush();
    }

    /**
     * Announces a notification for its fire time.
     */
    public function announce(ScheduledNotification $notification): void
    {
        $this->afterCommit->dispatch(new FireNotification($notification->getId()->toRfc4122()), [DelayStamp::delayUntil($notification->getFireAt())]);
    }

    private function context(User $user, NotificationPreferences $preferences): PlanContext
    {
        return new PlanContext(
            $user,
            new \DateTimeZone($preferences->getTimeZone()),
            $this->rules->of($user),
            $this->selfAddresses->of($user),
            $this->entityManager->find(UserLocation::class, $user),
        );
    }

    private function planWithin(CalendarObject $object, \DateTimeImmutable $now, \DateTimeImmutable $until, PlanContext $context): void
    {
        $rows = $this->events->findForObject($object);
        $existing = $this->notifications->findPlannedFor(array_values($rows));

        $wanted = [];
        if ($object->getCalendar()->isVisible()) {
            try {
                foreach (Occurrences::instances(CalendarDocument::parse($object->getIcal()), $now->modify('-1 day'), $until, $context->zone, OccurrenceExpander::MAX_PER_SERIES) as $instance) {
                    $key = CalendarDocument::recurrenceKey($instance);
                    $row = $rows[$key] ?? $rows[''] ?? null;
                    if (null === $row) {
                        continue;
                    }
                    foreach ($this->plansFor($row, $key, $instance, $context, $now) as $plan) {
                        $wanted[$plan->key($row)] = [$row, $plan];
                    }
                }
            } catch (MaxInstancesExceededException|\Sabre\VObject\InvalidDataException|InvalidCalendarData $e) {
                $this->logger->warning('Notifications could not be planned', ['object' => $object->getId()->toRfc4122(), 'error' => $e->getMessage()]);
            }
        }

        $tooLate = $now->modify(self::TOO_LATE);
        foreach ($wanted as $key => [$row, $plan]) {
            $notification = $existing[$key] ?? null;
            unset($existing[$key]);
            if (null !== $notification) {
                if (!$notification->isDelivered() && $notification->replan($plan->fireAt, $plan->startsAt, $plan->title, $plan->body, $plan->channels)) {
                    $this->announce($notification);
                }
                continue;
            }
            if ($plan->fireAt < $tooLate) {
                continue;
            }
            $notification = new ScheduledNotification($context->user, $row, $plan->occurrenceKey, $plan->kind, $plan->slot, $plan->fireAt, $plan->startsAt, $plan->title, $plan->body, $plan->channels);
            $this->entityManager->persist($notification);
            $this->announce($notification);
        }

        foreach ($existing as $stale) {
            if (!$stale->isDelivered()) {
                $this->entityManager->remove($stale);
            }
        }
    }

    /**
     * @return list<Plan>
     */
    private function plansFor(CalendarEvent $row, string $key, VEvent $instance, PlanContext $context, \DateTimeImmutable $now): array
    {
        if (EventStatus::Cancelled === $row->getStatus() || ParticipationStatus::Declined === $row->getSelfPartstat()) {
            return [];
        }
        $start = Props::instant($instance, 'DTSTART', $context->zone);
        $end = Props::instant($instance, 'DTEND', $context->zone) ?? ($row->isAllDay() && null !== $start ? $start->modify('+1 day') : $start);
        if (null === $start || null === $end) {
            return [];
        }

        $rule = ReminderRules::match($context->rules, $row, $context->self);
        $title = '' === $row->getSummary() ? '(no title)' : $row->getSummary();
        $plans = [];

        foreach ($this->reminders($row, $rule, $start, $end, $context) as $slot => [$fireAt, $channels]) {
            $plans[] = new Plan($key, NotificationKind::Reminder, $slot, $fireAt, $start, $title, self::reminderBody($row, $start, $end, $context->zone), $channels);
        }

        $location = trim($row->getLocation());
        $others = ReminderRules::hasOthers($row, $context->self);
        if (!$row->isAllDay() && '' !== $location && (null !== $row->getTravelMinutes() || true === $rule?->isLeaveNow())) {
            $travel = $row->getTravelMinutes();
            if (null !== $travel) {
                $plans[] = new Plan($key, NotificationKind::LeaveNow, 'leave', $start->modify(\sprintf('-%d minutes', $travel + self::LEAVE_MARGIN_MINUTES)), $start, $title, self::leaveBody($travel, $location), ['push']);
            } elseif (null !== $context->location) {
                // Estimated when it fires, and moved to the right moment then.
                $plans[] = new Plan($key, NotificationKind::LeaveNow, 'leave:estimated', $start->modify(\sprintf('-%d minutes', self::LEAVE_CHECK_MINUTES)), $start, $title, self::leaveBody(null, $location), ['push']);
            }
        }
        if ($others && null !== $rule?->getBriefMinutes()) {
            $plans[] = new Plan($key, NotificationKind::Brief, 'brief', $start->modify(\sprintf('-%d minutes', $rule->getBriefMinutes())), $start, 'Brief: '.$title, 'Who is coming and what you discussed.', ['push']);
        }
        if ($others && !$row->isAllDay() && true === $rule?->isFollowUp() && $end > $now->modify('-1 hour')) {
            $plans[] = new Plan($key, NotificationKind::FollowUp, 'follow', $end, $start, 'After: '.$title, 'How did it go? Add notes or follow-ups while it is fresh.', ['push']);
        }

        return $plans;
    }

    /**
     * The event's own reminders, or its rule's when it uses the defaults.
     *
     * @return array<string, array{\DateTimeImmutable, list<string>}> by slot
     */
    private function reminders(CalendarEvent $row, ?ReminderRule $rule, \DateTimeImmutable $start, \DateTimeImmutable $end, PlanContext $context): array
    {
        $reminders = [];
        if ($row->usesDefaultReminders()) {
            foreach ($rule?->getReminders() ?? [] as $index => $reminder) {
                if (null !== $reminder['dayBeforeAt']) {
                    [$hour, $minute] = array_map(intval(...), explode(':', $reminder['dayBeforeAt']));
                    $fireAt = $start->setTimezone($context->zone)->modify('-1 day')->setTime($hour, $minute);
                } else {
                    $fireAt = $start->modify(\sprintf('-%d minutes', $reminder['minutesBefore'] ?? 0));
                }
                $reminders['rule:'.$index] = [$fireAt, $reminder['channels']];
            }

            return $reminders;
        }

        foreach ($row->getReminders() as $index => $reminder) {
            if (null !== $reminder['at']) {
                $fireAt = RecurrenceKey::toDateTime($reminder['at']);
            } else {
                $fireAt = self::offset('end' === $reminder['relatedTo'] ? $end : $start, (string) $reminder['trigger']);
            }
            if (false === $fireAt || null === $fireAt) {
                continue;
            }
            $reminders['valarm:'.$index] = [$fireAt, $reminder['channels']];
        }

        return $reminders;
    }

    /** Applies an iCalendar duration like -PT15M or P1D to a moment. */
    private static function offset(\DateTimeImmutable $base, string $duration): ?\DateTimeImmutable
    {
        $negative = str_starts_with($duration, '-');
        try {
            $interval = new \DateInterval(ltrim($duration, '+-'));
        } catch (\Exception) {
            return null;
        }

        return $negative ? $base->sub($interval) : $base->add($interval);
    }

    private static function reminderBody(CalendarEvent $row, \DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeZone $zone): string
    {
        $when = $row->isAllDay()
            ? $start->setTimezone($zone)->format('l j F').' (all day)'
            : $start->setTimezone($zone)->format('l j F, H:i').' to '.$end->setTimezone($zone)->format('H:i');
        $location = trim($row->getLocation());

        return '' === $location ? $when : $when.', '.$location;
    }

    public static function leaveBody(?int $travelMinutes, string $location): string
    {
        return null === $travelMinutes
            ? \sprintf('Time to leave for %s.', $location)
            : \sprintf('Leave now: about %d minutes to %s.', $travelMinutes, $location);
    }
}
