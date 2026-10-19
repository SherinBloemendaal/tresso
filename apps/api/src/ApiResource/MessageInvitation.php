<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Calendar\ICalendar\EventData;
use App\Security\Scope;
use App\State\MessageInvitationProvider;

/**
 * The calendar object a message carries, as the invitation card shows it: what the event is, whether this message
 * invites, updates, cancels or answers, the user's own response, and what else is in their calendar at that time.
 * `eventId` is the event in the user's calendar, when there is one, for RSVP and opening it.
 *
 * @phpstan-import-type Address from EventData
 * @phpstan-import-type Attendee from EventData
 * @phpstan-import-type Conference from EventData
 */
#[ApiResource(
    shortName: 'MessageInvitation',
    routePrefix: '/api/v1',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/messages/{id}/invitation',
            security: "is_granted('".Scope::ROLE_MAIL_READ."') and is_granted('".Scope::ROLE_CALENDAR_READ."')",
            provider: MessageInvitationProvider::class,
        ),
    ],
)]
final readonly class MessageInvitation
{
    /**
     * @param array{date: string}|array{dateTime: string, timeZone: ?string} $start
     * @param array{date: string}|array{dateTime: string, timeZone: ?string} $end
     * @param Address|null                                                   $organizer
     * @param list<Attendee>                                                 $attendees
     * @param Conference|null                                                $conference
     * @param list<CalendarOccurrence>                                       $conflicts
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $method,
        /** new, update, cancelled, reply or outdated (an older version than the calendar has). */
        public string $kind,
        public string $uid,
        public ?string $recurrenceId,
        public int $sequence,
        public string $summary,
        public string $description,
        public string $location,
        public bool $allDay,
        public array $start,
        public array $end,
        public ?string $recurrence,
        public ?array $organizer,
        public array $attendees,
        public ?array $conference,
        public ?string $eventId,
        public ?string $selfResponse,
        public bool $canRespond,
        public array $conflicts,
    ) {
    }
}
