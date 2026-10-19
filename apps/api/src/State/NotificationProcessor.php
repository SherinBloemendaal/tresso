<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Notification\SnoozeInput;
use App\ApiResource\NotificationResource;
use App\Entity\ScheduledNotification;
use App\Notification\NotificationPlanner;
use App\Repository\ScheduledNotificationRepository;
use App\Security\CurrentUser;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
                $notification->snooze($now->modify(\sprintf('+%d minutes', $data->minutes)));
                $this->entityManager->flush();
                $this->planner->announce($notification);

                return $notification;
            }
            $notification->dismiss($now);
            $this->entityManager->flush();

            return $notification;
        }));
    }
}
