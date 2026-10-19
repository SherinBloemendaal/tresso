<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MeetingBriefResource;
use App\Calendar\Assistant\MeetingBriefWriter;
use App\Entity\CalendarEvent;
use App\Repository\CalendarEventRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<MeetingBriefResource>
 */
final readonly class MeetingBriefProvider implements ProviderInterface
{
    public function __construct(
        private CalendarEventRepository $events,
        private MeetingBriefWriter $briefs,
        private CurrentUser $currentUser,
        private RequestStack $requests,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MeetingBriefResource
    {
        $id = $uriVariables['id'] ?? null;
        $event = \is_string($id) ? $this->events->findOneOwnedBy($this->currentUser->get(), $id) : null;
        if (!$event instanceof CalendarEvent) {
            throw new NotFoundHttpException('Event not found.');
        }
        $recurrenceId = $this->requests->getCurrentRequest()?->query->get('recurrenceId');
        $key = \is_string($recurrenceId) && '' !== $recurrenceId ? $recurrenceId : $event->getRecurrenceKey();
        $brief = $this->briefs->brief($event, $key);

        return new MeetingBriefResource($event->getId()->toRfc4122(), '' === $key ? null : $key, $brief->people, $brief->threads, $brief->summary, $brief->headline());
    }
}
