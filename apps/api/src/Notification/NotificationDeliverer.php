<?php

declare(strict_types=1);

namespace App\Notification;

use App\Calendar\Assistant\MeetingBriefWriter;
use App\Calendar\OccurrenceExpander;
use App\Entity\ScheduledNotification;
use App\Enum\NotificationChannel;
use App\Enum\NotificationKind;
use App\Notification\Channel\PushMessage;
use App\Repository\NotificationPreferencesRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Delivers a calendar notification when its time has come. First, under a lock on the notification: still current;
 * leave-now waiting for the phone's estimate asks the phone for one instead; then quiet hours, the working hours of
 * work calendars and focus blocks may move it later or make it silent, except for important events; then it is
 * marked delivered. Then the NotificationDispatcher sends it, once per target and round.
 */
final readonly class NotificationDeliverer
{
    /** A notification is only delivered this close to its fire time; a later one is waiting for its own message. */
    private const int EARLY_SECONDS = 30;
    /** A notification this late is not delivered any more, nor sent again after a failure. */
    public const string MAX_LATE = '2 hours';
    /** A reminder (not a follow-up) this long after its event started (or its own later time) is not delivered any more. */
    private const string LATE_AFTER_START = '+15 minutes';
    /** How long push services keep trying. */
    private const string EXPIRES = '+2 hours';
    /** The silent push that asks a phone for a leave-now estimate. */
    public const string ESTIMATE_KIND = 'leave_estimate';
    /** How often the phone is asked again while no estimate came. */
    private const string ESTIMATE_AGAIN = '+15 minutes';
    /** An estimate this close to the moment to leave is too late to be of use. */
    private const int LAST_ESTIMATE_MINUTES = 5;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private NotificationPreferencesRepository $preferences,
        private NotificationPlanner $planner,
        private OccurrenceExpander $occurrences,
        private MeetingBriefWriter $briefs,
        private NotificationDispatcher $dispatcher,
    ) {
    }

    public function fire(string $notificationId, \DateTimeImmutable $now): void
    {
        if (!Uuid::isValid($notificationId)) {
            return;
        }
        /** @var array{?ScheduledNotification, ?ScheduledNotification, list<array{string, ?\App\Entity\NotificationDevice, ?string}>} $next */
        $next = $this->entityManager->wrapInTransaction(function () use ($notificationId, $now): array {
            [$deliver, $askEstimate, $retry] = $this->prepare(Uuid::fromString($notificationId), $now);
            // Claimed in the same transaction that marks it delivered: a crash before the sends leaves claimed rows,
            // which the sweep tries again, never a delivered notification nobody sends.
            $claimed = null === $deliver ? [] : $this->dispatcher->claim($deliver->getUser(), self::deliveryKey($deliver), NotificationChannelChoice::fromValues($deliver->getChannels()), !$retry);

            return [$deliver, $askEstimate, $claimed];
        });
        [$deliver, $askEstimate, $claimed] = $next;
        if (null !== $deliver && [] !== $claimed) {
            $this->dispatcher->deliver($claimed, $this->message($deliver, $now), self::deliveryKey($deliver), $now);
        }
        if (null !== $askEstimate) {
            $this->askForEstimate($askEstimate, $now);
        }
        $this->entityManager->clear();
    }

    /** One delivery per notification and round: a snooze starts a new round, so the devices show it again. */
    public static function deliveryKey(ScheduledNotification $notification): string
    {
        return \sprintf('calendar:%s:%d', $notification->getId()->toRfc4122(), $notification->getRound());
    }

    /**
     * What to do now: the notification to send (marked delivered), or the leave-now notification to ask the phone
     * an estimate for; neither when there is nothing to do (yet).
     *
     * The third value tells whether this is a retry of a delivery made before.
     *
     * @return array{?ScheduledNotification, ?ScheduledNotification, bool}
     */
    private function prepare(Uuid $id, \DateTimeImmutable $now): array
    {
        $notification = $this->entityManager->find(ScheduledNotification::class, $id, LockMode::PESSIMISTIC_WRITE);
        if (null === $notification || null !== $notification->getDismissedAt()) {
            return [null, null, false];
        }
        if ($notification->isDelivered()) {
            // Delivered in this round: send again what failed or got stuck; the dispatcher leaves what went out alone.
            $recent = $notification->getDeliveredAt() > $now->modify('-'.self::MAX_LATE);

            return [$recent ? $notification : null, null, true];
        }
        $event = $notification->getEvent();
        if (null === $event) {
            $this->entityManager->remove($notification);
            $this->entityManager->flush();

            return [null, null, false];
        }
        if ($notification->getFireAt()->getTimestamp() > $now->getTimestamp() + self::EARLY_SECONDS) {
            return [null, null, false];
        }
        if (self::isStale($notification, $now)) {
            // After an outage, or for an event already under way: a late reminder is noise.
            $this->entityManager->remove($notification);
            $this->entityManager->flush();

            return [null, null, false];
        }

        $user = $notification->getUser();
        $preferences = $this->preferences->forUser($user);

        if (NotificationKind::LeaveNow === $notification->getKind() && NotificationPlanner::LEAVE_ESTIMATED_SLOT === $notification->getSlot() && null === $notification->getEstimatedMinutes()) {
            // No estimate from the phone yet: ask it, and look again in a while. Without one in time there is no
            // reminder rather than a guessed one.
            $latest = $notification->getOccurrenceStartsAt()->modify(\sprintf('-%d minutes', $preferences->getExtraTravelMinutes() + self::LAST_ESTIMATE_MINUTES));
            if ($now >= $latest) {
                $notification->dismiss($now);
                $this->entityManager->flush();

                return [null, null, false];
            }
            $notification->postpone(min($latest, $now->modify(self::ESTIMATE_AGAIN)));
            $this->entityManager->flush();
            $this->planner->announce($notification);

            return [null, $notification, false];
        }
        if (NotificationKind::Brief === $notification->getKind()) {
            $notification->replan($notification->getFireAt(), $notification->getOccurrenceStartsAt(), $notification->getOccurrenceStart(), $notification->getTitle(), $this->briefs->brief($event, $notification->getOccurrenceKey())->headline(), $notification->getChannels());
        }

        $important = $event->isImportant();
        $silent = false;
        $quietEnd = $important ? null : QuietHours::endOf($preferences, $now);
        if (null !== $quietEnd) {
            if ($notification->getOccurrenceStartsAt() > $quietEnd && NotificationKind::FollowUp !== $notification->getKind()) {
                return $this->postpone($notification, $quietEnd);
            }
            $silent = true;
        }
        $workQuiet = !$important && NotificationKind::LeaveNow !== $notification->getKind() && $preferences->isWorkCalendarsQuiet()
            && $event->getCalendar()->isWork() && !WorkingHours::contains($preferences, $now);
        if ($workQuiet) {
            $workStart = WorkingHours::nextStart($preferences, $now);
            if (null !== $workStart && $notification->getOccurrenceStartsAt() > $workStart && NotificationKind::FollowUp !== $notification->getKind()) {
                return $this->postpone($notification, $workStart);
            }
            $silent = true;
        }
        // Meetings with others starting and leave-now come through a focus block; the rest waits for its end, or comes
        // silently when the event starts before the block ends.
        $comesThrough = $important || NotificationKind::LeaveNow === $notification->getKind() || (NotificationKind::Reminder === $notification->getKind() && [] !== $event->getAttendees());
        if (!$comesThrough && $preferences->isHoldDuringFocus()) {
            $focusEnd = $this->focusBlockEnd($notification, $now);
            if (null !== $focusEnd) {
                if ($notification->getOccurrenceStartsAt() > $focusEnd || NotificationKind::FollowUp === $notification->getKind()) {
                    return $this->postpone($notification, $focusEnd);
                }
                $silent = true;
            }
        }
        if (!$silent && !$important && $preferences->isFocusDuringBusy() && NotificationKind::LeaveNow !== $notification->getKind() && null !== $event->getSelfPartstat() && $this->inBusyEvent($notification, $now)) {
            $silent = true;
        }

        $notification->markDelivered($now, $silent);
        $this->entityManager->flush();

        return [$notification, null, false];
    }

    private static function isStale(ScheduledNotification $notification, \DateTimeImmutable $now): bool
    {
        if ($notification->getFireAt() < $now->modify('-'.self::MAX_LATE)) {
            return true;
        }

        // Alarms set relative to the end, and snoozes, may come after the start on purpose.
        $due = max($notification->getOccurrenceStartsAt(), $notification->getFireAt());

        return NotificationKind::FollowUp !== $notification->getKind() && $now > $due->modify(self::LATE_AFTER_START);
    }

    /**
     * @return array{null, null, false}
     */
    private function postpone(ScheduledNotification $notification, \DateTimeImmutable $until): array
    {
        $notification->postpone($until);
        $this->entityManager->flush();
        $this->planner->announce($notification);

        return [null, null, false];
    }

    /**
     * Asks the user's phones, silently, for their position's travel estimate to this event (they answer with POST
     * /calendar-events/{id}/leave-estimate). The position stays on the phone until it sends that one estimate.
     */
    private function askForEstimate(ScheduledNotification $notification, \DateTimeImmutable $now): void
    {
        $event = $notification->getEvent();
        if (null === $event) {
            return;
        }
        $data = ['eventId' => $event->getId()->toRfc4122()];
        if ('' !== $notification->getOccurrenceKey() && !$event->isOverride()) {
            $data['recurrenceId'] = $notification->getOccurrenceKey();
        }
        $this->dispatcher->send(
            $notification->getUser(),
            new PushMessage($notification->getId()->toRfc4122(), self::ESTIMATE_KIND, '', '', null, true, $now->modify('+10 minutes'), $data),
            \sprintf('leave-estimate:%s:%d', $notification->getId()->toRfc4122(), $now->getTimestamp()),
            new NotificationChannelChoice(NotificationChannel::Phone),
            $now,
        );
    }

    /** The end of the focus block the user is in now (busy, only them, titled "Focus ..."), or null. */
    private function focusBlockEnd(ScheduledNotification $notification, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $eventId = $notification->getEvent()?->getId()->toRfc4122();
        foreach ($this->occurrences->expand($notification->getUser(), [], $now, $now->modify('+1 minute'), new \DateTimeZone('UTC')) as $occurrence) {
            if ($occurrence->eventId === $eventId || $occurrence->allDay || 'busy' !== $occurrence->transparency || 'cancelled' === $occurrence->status || 0 !== $occurrence->attendeeCount) {
                continue;
            }
            if (!str_starts_with(mb_strtolower(trim($occurrence->summary)), 'focus') || !isset($occurrence->end['dateTime'])) {
                continue;
            }

            return new \DateTimeImmutable($occurrence->end['dateTime']);
        }

        return null;
    }

    /** Whether the user is in another busy event they take part in right now. */
    private function inBusyEvent(ScheduledNotification $notification, \DateTimeImmutable $now): bool
    {
        $eventId = $notification->getEvent()?->getId()->toRfc4122();
        foreach ($this->occurrences->expand($notification->getUser(), [], $now, $now->modify('+1 minute'), new \DateTimeZone('UTC')) as $occurrence) {
            if ($occurrence->eventId === $eventId || $occurrence->allDay || 'busy' !== $occurrence->transparency || 'cancelled' === $occurrence->status) {
                continue;
            }
            if (\in_array($occurrence->selfResponse, ['declined', 'needs-action'], true)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function message(ScheduledNotification $notification, \DateTimeImmutable $now): PushMessage
    {
        $event = $notification->getEvent();
        $path = null === $event ? '/calendar' : '/calendar/events/'.$event->getId()->toRfc4122().('' === $notification->getOccurrenceKey() ? '' : '?occurrence='.$notification->getOccurrenceKey());

        return new PushMessage(
            $notification->getId()->toRfc4122(),
            $notification->getKind()->value,
            $notification->getTitle(),
            $notification->getBody(),
            $path,
            $notification->isSilent(),
            $now->modify(self::EXPIRES),
        );
    }
}
