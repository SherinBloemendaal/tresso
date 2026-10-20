<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

/**
 * What to find time for: a duration in a window, with whom, and how to propose it.
 *
 * Planning a todo is a query without attendees: `new MeetingTimeQuery($now, $deadline, 30, limit: 1)` asks for the
 * earliest free half hour before the deadline, and `ignore` leaves out the todo's own planned time when it is moved.
 */
final readonly class MeetingTimeQuery
{
    /** The longest window one search looks through. */
    public const string MAX_WINDOW = 'P92D';
    public const int MAX_DURATION_MINUTES = 1440;
    public const int MAX_SLOTS = 50;
    /** Slot starts are on these multiples of minutes past the hour in the user's zone. */
    public const array STEPS = [5, 10, 15, 20, 30, 60];

    /**
     * @param list<MeetingAttendee> $attendees   besides the user
     * @param list<string>|null     $calendarIds the calendars whose busy time counts; null for the visible ones
     * @param list<string>          $ignore      busy time to leave out, by reference (an event or occurrence id)
     *
     * @throws \InvalidArgumentException when a value is outside its limits
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public int $durationMinutes,
        public array $attendees = [],
        public int $stepMinutes = 15,
        public int $limit = 10,
        /** At most this many slots on one day (in the user's zone), so proposals spread over the window; null for no limit. */
        public ?int $perDay = null,
        /** The meeting is at a place: travel time is kept free around every busy time next to it. */
        public bool $inPerson = false,
        /** Only within working hours; false searches the whole window (focus blocks and busy time still count). */
        public bool $workingHoursOnly = true,
        public ?array $calendarIds = null,
        public array $ignore = [],
        /** The zone of working hours, focus blocks and all-day events; null for the user's own. */
        public ?\DateTimeZone $timeZone = null,
    ) {
        if ($to <= $from) {
            throw new \InvalidArgumentException('to must be after from.');
        }
        if ($to > $from->add(new \DateInterval(self::MAX_WINDOW))) {
            throw new \InvalidArgumentException('The window can be at most 92 days.');
        }
        if ($durationMinutes < 5 || $durationMinutes > self::MAX_DURATION_MINUTES) {
            throw new \InvalidArgumentException('The duration must be between 5 minutes and 24 hours.');
        }
        if (!\in_array($stepMinutes, self::STEPS, true)) {
            throw new \InvalidArgumentException('The step must be 5, 10, 15, 20, 30 or 60 minutes.');
        }
        if ($limit < 1 || $limit > self::MAX_SLOTS || (null !== $perDay && ($perDay < 1 || $perDay > self::MAX_SLOTS))) {
            throw new \InvalidArgumentException('Ask for between 1 and 50 slots.');
        }
    }

    /**
     * @return list<string>
     */
    public function attendeeAddresses(): array
    {
        return array_map(static fn (MeetingAttendee $attendee): string => $attendee->address, $this->attendees);
    }
}
