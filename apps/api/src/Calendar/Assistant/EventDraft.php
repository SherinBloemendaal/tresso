<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

use App\Calendar\ICalendar\EventData;

/**
 * An event that is not saved: what the editor opens with. Its fields have the editor's shape (see EventInput), so a
 * client fills the editor with it and the user saves; nothing here saves an event by itself.
 */
final readonly class EventDraft
{
    /**
     * @param array{date: string}|array{dateTime: string, timeZone: ?string} $start
     * @param array{date: string}|array{dateTime: string, timeZone: ?string} $end
     * @param list<array{address: string, name: ?string, role: string}>      $attendees
     */
    public function __construct(
        public string $summary,
        public string $description,
        public string $location,
        public array $start,
        public array $end,
        public ?string $recurrence = null,
        public array $attendees = [],
        public ?string $conferenceUrl = null,
    ) {
    }

    public static function fromEventData(EventData $data): self
    {
        $url = $data->conference['url'] ?? null;

        return new self(
            $data->summary,
            $data->description,
            $data->location,
            $data->start->toArray(),
            $data->end->toArray(),
            $data->rrule,
            array_map(static fn (array $attendee): array => ['address' => $attendee['address'], 'name' => $attendee['name'], 'role' => $attendee['role']], $data->attendees),
            null !== $url && str_starts_with($url, 'https://') ? $url : null,
        );
    }
}
