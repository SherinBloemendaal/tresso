<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Notification\SnoozeInput;
use App\ApiResource\NotificationResource;
use App\Calendar\OccurrenceExpander;
use App\Entity\ScheduledNotification;
use App\Enum\SnoozeChoice;
use App\Notification\NotificationPlanner;
use App\Repository\ScheduledNotificationRepository;
use App\Security\CurrentUser;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProcessorInterface<SnoozeInput|NotificationResource, NotificationResource>
 */
final readonly class NotificationProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ScheduledNotificationRepository $notifications,
        private NotificationPlanner $planner,
        private CurrentUser $currentUser,
        private OccurrenceExpander $occurrences,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): NotificationResource
    {
        $id = $uriVariables['id'] ?? null;
        $owned = \is_string($id) ? $this->notifications->findOneOwnedBy($this->currentUser->get(), $id) : null;
        if (!$owned instanceof ScheduledNotification) {
            throw new NotFoundHttpException('Notification not found.');
        }

        return NotificationResource::fromEntity($this->entityManager->wrapInTransaction(function () use ($owned, $data): ScheduledNotification {
            $notification = $this->entityManager->find(ScheduledNotification::class, $owned->getId(), LockMode::PESSIMISTIC_WRITE) ?? throw new NotFoundHttpException('Notification not found.');
            $now = new \DateTimeImmutable();
            if ($data instanceof SnoozeInput) {
                $notification->snooze($this->snoozeUntil($notification, $data, $now));
                $this->entityManager->flush();
                $this->planner->announce($notification);

                return $notification;
            }
            $notification->dismiss($now);
            $this->entityManager->flush();

            return $notification;
        }));
    }

    private function snoozeUntil(ScheduledNotification $notification, SnoozeInput $input, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $choice = null === $input->until ? null : SnoozeChoice::from($input->until);
        $until = match ($choice) {
            null => $now->modify(\sprintf('+%d minutes', $input->minutes)),
            SnoozeChoice::Minutes5 => $now->modify('+5 minutes'),
            SnoozeChoice::Minutes10 => $now->modify('+10 minutes'),
            SnoozeChoice::Minutes15 => $now->modify('+15 minutes'),
            SnoozeChoice::BeforeStart => $notification->getOccurrenceStartsAt()->modify('-1 minute'),
            SnoozeChoice::Start => $notification->getOccurrenceStartsAt(),
            SnoozeChoice::MeetingEnd => $this->currentMeetingEnd($notification, $now) ?? throw new UnprocessableEntityHttpException('You are not in a meeting now.'),
        };
        if ($until <= $now) {
            throw new UnprocessableEntityHttpException('That moment has passed.');
        }

        return $until;
    }

    /** The end of the busy meeting (other than this notification's event) the user is in now. */
    private function currentMeetingEnd(ScheduledNotification $notification, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $eventId = $notification->getEvent()?->getId()->toRfc4122();
        foreach ($this->occurrences->expand($notification->getUser(), [], $now, $now->modify('+1 minute'), new \DateTimeZone('UTC')) as $occurrence) {
            if ($occurrence->eventId === $eventId || $occurrence->allDay || 'busy' !== $occurrence->transparency || 'cancelled' === $occurrence->status || 'declined' === $occurrence->selfResponse) {
                continue;
            }
            if (isset($occurrence->end['dateTime'])) {
                return new \DateTimeImmutable($occurrence->end['dateTime']);
            }
        }

        return null;
    }
}
