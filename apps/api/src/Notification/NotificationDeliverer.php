<?php

declare(strict_types=1);

namespace App\Notification;

use App\Calendar\Assistant\MeetingBriefWriter;
use App\Calendar\OccurrenceExpander;
use App\Entity\ScheduledNotification;
use App\Entity\UserLocation;
use App\Enum\NotificationKind;
use App\Notification\Channel\PushMessage;
use App\Notification\Travel\TravelTimeEstimator;
use App\Repository\NotificationPreferencesRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Delivers a calendar notification when its time has come. First, under a lock on the notification: still current,
 * not quiet (or moved to the end of the quiet hours), not drowned out by focus, leave-now re-estimated, and marked
 * delivered. Then the NotificationDispatcher sends it, once per target and round.
 */
final readonly class NotificationDeliverer
{
    /** A notification is only delivered this close to its fire time; a later one is waiting for its own message. */
    private const int EARLY_SECONDS = 30;
    /** How long push services keep trying. */
    private const string EXPIRES = '+2 hours';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private NotificationPreferencesRepository $preferences,
        private NotificationPlanner $planner,
        private OccurrenceExpander $occurrences,
        private TravelTimeEstimator $travel,
        private MeetingBriefWriter $briefs,
        private NotificationDispatcher $dispatcher,
    ) {
    }

    public function fire(string $notificationId, \DateTimeImmutable $now): void
    {
        if (!Uuid::isValid($notificationId)) {
            return;
        }
        $notification = $this->entityManager->wrapInTransaction(fn (): ?ScheduledNotification => $this->prepare(Uuid::fromString($notificationId), $now));
        if (null !== $notification) {
            $this->dispatcher->send(
                $notification->getUser(),
                $this->message($notification, $now),
                self::deliveryKey($notification),
                NotificationChannelChoice::fromValues($notification->getChannels()),
                $now,
            );
        }
        $this->entityManager->clear();
    }

    /** One delivery per notification and round: a snooze starts a new round, so the devices show it again. */
    public static function deliveryKey(ScheduledNotification $notification): string
    {
        return \sprintf('calendar:%s:%d', $notification->getId()->toRfc4122(), $notification->getRound());
    }

    /**
     * The notification to send now, marked delivered; null when there is nothing to send (yet).
     */
    private function prepare(Uuid $id, \DateTimeImmutable $now): ?ScheduledNotification
    {
        $notification = $this->entityManager->find(ScheduledNotification::class, $id, LockMode::PESSIMISTIC_WRITE);
        if (null === $notification || $notification->isDelivered() || null !== $notification->getDismissedAt()) {
            return null;
        }
        $event = $notification->getEvent();
        if (null === $event) {
            $this->entityManager->remove($notification);
            $this->entityManager->flush();

            return null;
        }
        if ($notification->getFireAt()->getTimestamp() > $now->getTimestamp() + self::EARLY_SECONDS) {
            return null;
        }

        $user = $notification->getUser();
        $preferences = $this->preferences->forUser($user);

        if (NotificationKind::LeaveNow === $notification->getKind() && 'leave:estimated' === $notification->getSlot()) {
            $leaveAt = $this->leaveAt($notification);
            if (null === $leaveAt) {
                // No route: no reminder rather than a wrong one.
                $notification->dismiss($now);
                $this->entityManager->flush();

                return null;
            }
            if ($leaveAt->getTimestamp() > $now->getTimestamp() + 60) {
                $notification->postpone(min($leaveAt, $now->modify('+15 minutes')));
                $this->entityManager->flush();
                $this->planner->announce($notification);

                return null;
            }
        }
        if (NotificationKind::Brief === $notification->getKind()) {
            $notification->replan($notification->getFireAt(), $notification->getOccurrenceStartsAt(), $notification->getTitle(), $this->briefs->brief($event, $notification->getOccurrenceKey())->headline(), $notification->getChannels());
        }

        $silent = false;
        $quietEnd = QuietHours::endOf($preferences, $now);
        if (null !== $quietEnd) {
            if ($notification->getOccurrenceStartsAt() > $quietEnd && NotificationKind::FollowUp !== $notification->getKind()) {
                $notification->postpone($quietEnd);
                $this->entityManager->flush();
                $this->planner->announce($notification);

                return null;
            }
            $silent = true;
        }
        if (!$silent && $preferences->isFocusDuringBusy() && NotificationKind::LeaveNow !== $notification->getKind() && null !== $event->getSelfPartstat() && $this->inBusyEvent($notification, $now)) {
            $silent = true;
        }

        $notification->markDelivered($now, $silent);
        $this->entityManager->flush();

        return $notification;
    }

    private function leaveAt(ScheduledNotification $notification): ?\DateTimeImmutable
    {
        $event = $notification->getEvent();
        $location = $this->entityManager->find(UserLocation::class, $notification->getUser());
        if (null === $event || null === $location || !$this->travel->isAvailable()) {
            return null;
        }
        $minutes = $this->travel->minutes($location->getLatitude(), $location->getLongitude(), $event->getLocation(), $notification->getOccurrenceStartsAt());
        if (null === $minutes) {
            return null;
        }
        $notification->replan($notification->getFireAt(), $notification->getOccurrenceStartsAt(), $notification->getTitle(), NotificationPlanner::leaveBody($minutes, $event->getLocation()), $notification->getChannels());

        return $notification->getOccurrenceStartsAt()->modify(\sprintf('-%d minutes', $minutes + NotificationPlanner::LEAVE_MARGIN_MINUTES));
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
