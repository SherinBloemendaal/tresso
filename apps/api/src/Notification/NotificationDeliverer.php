<?php

declare(strict_types=1);

namespace App\Notification;

use App\Calendar\Assistant\MeetingBriefWriter;
use App\Calendar\OccurrenceExpander;
use App\Entity\NotificationDelivery;
use App\Entity\NotificationDevice;
use App\Entity\ScheduledNotification;
use App\Entity\UserLocation;
use App\Enum\DeviceKind;
use App\Enum\NotificationKind;
use App\Notification\Channel\EmailNotifier;
use App\Notification\Channel\ExpoPushSender;
use App\Notification\Channel\PushMessage;
use App\Notification\Channel\PushResult;
use App\Notification\Channel\WebPushSender;
use App\Notification\Travel\TravelTimeEstimator;
use App\Repository\NotificationDeviceRepository;
use App\Repository\NotificationPreferencesRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Delivers a notification when its time has come. First, under a lock on the notification: still current, not quiet
 * (or moved to the end of the quiet hours), not drowned out by focus, leave-now re-estimated, and one delivery record
 * per target and round, so nothing is ever sent twice. Then the sends, outside the transaction. The desktop app gets
 * delivered notifications through the delta, so its record is complete at once.
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
        private NotificationDeviceRepository $devices,
        private NotificationPlanner $planner,
        private OccurrenceExpander $occurrences,
        private TravelTimeEstimator $travel,
        private MeetingBriefWriter $briefs,
        private WebPushSender $webPush,
        private ExpoPushSender $expo,
        private EmailNotifier $email,
        private LoggerInterface $logger,
    ) {
    }

    public function fire(string $notificationId, \DateTimeImmutable $now): void
    {
        if (!Uuid::isValid($notificationId)) {
            return;
        }
        /** @var list<array{NotificationDelivery, ?NotificationDevice, ?string}> $sends */
        $sends = $this->entityManager->wrapInTransaction(fn (): array => $this->prepare(Uuid::fromString($notificationId), $now));
        if ([] === $sends) {
            $this->entityManager->clear();

            return;
        }

        $message = $this->message($sends[0][0]->getNotification(), $now);
        foreach ($sends as [$delivery, $device, $address]) {
            $result = match (true) {
                null !== $address => $this->email->send($address, $message),
                DeviceKind::WebPush === $device?->getKind() => $this->webPush->send($device, $message, $now),
                DeviceKind::Expo === $device?->getKind() => $this->expo->send($device, $message, $now),
                default => PushResult::sent(),
            };
            $this->record($delivery, $device, $result);
        }
        $this->entityManager->clear();
    }

    /**
     * @return list<array{NotificationDelivery, ?NotificationDevice, ?string}> what to send, with the device or email
     *                                                                           address
     */
    private function prepare(Uuid $id, \DateTimeImmutable $now): array
    {
        $notification = $this->entityManager->find(ScheduledNotification::class, $id, LockMode::PESSIMISTIC_WRITE);
        if (null === $notification || $notification->isDelivered() || null !== $notification->getDismissedAt()) {
            return [];
        }
        $event = $notification->getEvent();
        if (null === $event) {
            $this->entityManager->remove($notification);
            $this->entityManager->flush();

            return [];
        }
        if ($notification->getFireAt()->getTimestamp() > $now->getTimestamp() + self::EARLY_SECONDS) {
            return [];
        }

        $user = $notification->getUser();
        $preferences = $this->preferences->forUser($user);

        if (NotificationKind::LeaveNow === $notification->getKind() && 'leave:estimated' === $notification->getSlot()) {
            $leaveAt = $this->leaveAt($notification);
            if (null === $leaveAt) {
                // No route: no reminder rather than a wrong one.
                $notification->dismiss($now);
                $this->entityManager->flush();

                return [];
            }
            if ($leaveAt->getTimestamp() > $now->getTimestamp() + 60) {
                $notification->postpone(min($leaveAt, $now->modify('+15 minutes')));
                $this->entityManager->flush();
                $this->planner->announce($notification);

                return [];
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

                return [];
            }
            $silent = true;
        }
        if (!$silent && $preferences->isFocusDuringBusy() && NotificationKind::LeaveNow !== $notification->getKind() && null !== $event->getSelfPartstat() && $this->inBusyEvent($notification, $now)) {
            $silent = true;
        }

        $notification->markDelivered($now, $silent);

        $targets = [];
        if (\in_array('push', $notification->getChannels(), true)) {
            foreach ($this->devices->findEnabled($user) as $device) {
                $targets[] = [$device->getId()->toRfc4122(), $device, null];
            }
        }
        if (\in_array('email', $notification->getChannels(), true)) {
            $targets[] = ['email', null, $preferences->getEmailAddress() ?? $user->getEmail()];
        }

        $sends = [];
        foreach ($targets as [$target, $device, $address]) {
            if (null !== $this->entityManager->getRepository(NotificationDelivery::class)->findOneBy(['notification' => $notification, 'target' => $target, 'round' => $notification->getRound()])) {
                continue;
            }
            $delivery = new NotificationDelivery($notification, $target, 'sending');
            $this->entityManager->persist($delivery);
            $sends[] = [$delivery, $device, $address];
        }
        $this->entityManager->flush();

        return $sends;
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

    private function record(NotificationDelivery $delivery, ?NotificationDevice $device, PushResult $result): void
    {
        $this->entityManager->wrapInTransaction(function () use ($delivery, $device, $result): void {
            $connection = $this->entityManager->getConnection();
            $connection->update('notification_delivery', ['status' => $result->status, 'error' => null === $result->error ? null : mb_substr($result->error, 0, 500)], ['id' => $delivery->getId()->toRfc4122()]);
            if ('gone' === $result->status && null !== $device) {
                // The push service forgot the subscription; so do we.
                $connection->delete('notification_device', ['id' => $device->getId()->toRfc4122()]);
            }
        });
        if ('failed' === $result->status) {
            $this->logger->warning('A notification could not be delivered', ['delivery' => $delivery->getId()->toRfc4122(), 'error' => $result->error]);
        }
    }
}
