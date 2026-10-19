<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\NotificationResource;
use App\Entity\ScheduledNotification;
use App\Repository\ScheduledNotificationRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<NotificationResource>
 */
final readonly class NotificationProvider implements ProviderInterface
{
    public function __construct(
        private ScheduledNotificationRepository $notifications,
        private CurrentUser $currentUser,
    ) {
    }

    /**
     * @return NotificationResource|list<NotificationResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): NotificationResource|array
    {
        $user = $this->currentUser->get();
        if ($operation instanceof CollectionOperationInterface) {
            $now = new \DateTimeImmutable();

            return array_map(NotificationResource::fromEntity(...), $this->notifications->findForList($user, $now->modify('-7 days'), $now->modify('+8 days')));
        }
        $id = $uriVariables['id'] ?? null;
        $notification = \is_string($id) ? $this->notifications->findOneOwnedBy($user, $id) : null;
        if (!$notification instanceof ScheduledNotification) {
            throw new NotFoundHttpException('Notification not found.');
        }

        return NotificationResource::fromEntity($notification);
    }
}
