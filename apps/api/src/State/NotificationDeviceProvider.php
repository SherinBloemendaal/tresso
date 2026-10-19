<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\NotificationDeviceResource;
use App\Entity\NotificationDevice;
use App\Repository\NotificationDeviceRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<NotificationDeviceResource>
 */
final readonly class NotificationDeviceProvider implements ProviderInterface
{
    public function __construct(
        private NotificationDeviceRepository $devices,
        private CurrentUser $currentUser,
    ) {
    }

    /**
     * @return NotificationDeviceResource|list<NotificationDeviceResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): NotificationDeviceResource|array
    {
        $user = $this->currentUser->get();
        if ($operation instanceof CollectionOperationInterface) {
            return array_map(NotificationDeviceResource::fromEntity(...), $this->devices->findOwnedBy($user));
        }
        $id = $uriVariables['id'] ?? null;
        $device = \is_string($id) ? $this->devices->findOneOwnedBy($user, $id) : null;
        if (!$device instanceof NotificationDevice) {
            throw new NotFoundHttpException('Device not found.');
        }

        return NotificationDeviceResource::fromEntity($device);
    }
}
