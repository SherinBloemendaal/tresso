<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\CalendarResource;
use App\Calendar\LocalCalendars;
use App\Entity\Calendar;
use App\Repository\CalendarRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Only ever returns the signed-in user's own calendars.
 *
 * @implements ProviderInterface<CalendarResource>
 */
final readonly class CalendarProvider implements ProviderInterface
{
    public function __construct(
        private CalendarRepository $calendars,
        private CurrentUser $currentUser,
        private LocalCalendars $localCalendars,
    ) {
    }

    /**
     * @return CalendarResource|list<CalendarResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CalendarResource|array
    {
        $owner = $this->currentUser->get();
        if ($operation instanceof CollectionOperationInterface) {
            $this->localCalendars->ensure($owner);

            return array_map(CalendarResource::fromEntity(...), $this->calendars->findOwnedBy($owner));
        }

        $id = $uriVariables['id'] ?? null;
        $calendar = \is_string($id) ? $this->calendars->findOneOwnedBy($owner, $id) : null;
        if (!$calendar instanceof Calendar) {
            throw new NotFoundHttpException('Calendar not found.');
        }

        return CalendarResource::fromEntity($calendar);
    }
}
