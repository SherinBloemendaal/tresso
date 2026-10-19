<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Calendar\ICalendar\EventTime;
use App\Enum\EventStatus;
use App\Enum\EventVisibility;
use App\Enum\Transparency;

/**
 * Everything the user can set on an event in the editor. A save always carries all of it.
 */
final readonly class EventChanges
{
    /**
     * @param list<array{address: string, name: ?string, role: string}> $attendees
     * @param list<array{minutesBefore: int, channels: list<string>}>  $reminders ignored when the defaults apply
     */
    public function __construct(
        public string $summary,
        public string $description,
        public string $location,
        public ?string $url,
        public EventTime $start,
        public EventTime $end,
        public ?string $rrule,
        public EventStatus $status,
        public Transparency $transparency,
        public EventVisibility $visibility,
        public array $attendees,
        public array $reminders,
        public ?string $conferenceUrl,
        public bool $useDefaultReminders = true,
        public ?int $travelMinutes = null,
        /** Important events reach the user also in quiet and outside working hours (PRIORITY 1). */
        public bool $important = false,
    ) {
        if ($start->isDate !== $end->isDate) {
            throw new \InvalidArgumentException('Start and end must both be dates or both be date-times.');
        }
    }
}
