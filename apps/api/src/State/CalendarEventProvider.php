<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\CalendarEventResource;
use App\Entity\CalendarEvent;
use App\Repository\CalendarEventRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<CalendarEventResource>
 */
final readonly class CalendarEventProvider implements ProviderInterface
{
    public function __construct(
        private CalendarEventRepository $events,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CalendarEventResource
    {
        $id = $uriVariables['id'] ?? null;
        $event = \is_string($id) ? $this->events->findOneOwnedBy($this->currentUser->get(), $id) : null;
        if (!$event instanceof CalendarEvent) {
            throw new NotFoundHttpException('Event not found.');
        }

        return CalendarEventResource::fromEntity($event);
    }
}
