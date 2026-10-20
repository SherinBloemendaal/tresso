<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

use App\Calendar\ICalendar\CalendarDocument;
use App\Calendar\ICalendar\EventReader;
use App\Calendar\ICalendar\InvalidCalendarData;
use App\Calendar\Invitation\InvitationApplier;
use App\Calendar\SelfAddresses;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\ItipMethod;
use App\Repository\CalendarEventRepository;
use App\Repository\CalendarInvitationRepository;
use App\Repository\CalendarObjectRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Events from the message's text/calendar parts, as the worker reported them: invitations and published events
 * (`REQUEST`, `PUBLISH`, `ADD`). Replies and cancellations change an event rather than describe a new one, so they are
 * left out; deadlines need the text and are not found here.
 */
#[AsAlias(EventExtractor::class)]
final readonly class CalendarPartEventExtractor implements EventExtractor
{
    private const array DESCRIBING = [ItipMethod::Request, ItipMethod::Publish, ItipMethod::Add];

    public function __construct(
        private CalendarInvitationRepository $invitations,
        private CalendarObjectRepository $objects,
        private CalendarEventRepository $events,
        private SelfAddresses $selfAddresses,
    ) {
    }

    public function readsText(): bool
    {
        return false;
    }

    public function extract(User $user, Message $message): array
    {
        $self = $this->selfAddresses->of($user);
        $events = [];
        foreach ($this->invitations->findForMessage($message) as $invitation) {
            if (!\in_array($invitation->getMethod(), self::DESCRIBING, true)) {
                continue;
            }
            $calendar = CalendarDocument::parse($invitation->getIcal());
            $vevent = CalendarDocument::master($calendar) ?? CalendarDocument::events($calendar)[0] ?? null;
            if (null === $vevent) {
                continue;
            }
            try {
                $data = EventReader::read($vevent, $calendar, $self);
            } catch (InvalidCalendarData) {
                continue;
            }
            $object = InvitationApplier::pick($this->objects->findForOwnerByUid($user, $invitation->getUid()), $message->getMailAccount());
            $rows = null === $object ? [] : $this->events->findForObject($object);
            $row = $rows[$invitation->getRecurrenceKey()] ?? $rows[''] ?? null;

            $events[] = new ExtractedEvent(EventDraft::fromEventData($data), 'calendar_part', $invitation->getUid(), $row?->getId()->toRfc4122());
        }

        return ['events' => $events, 'deadlines' => []];
    }
}
