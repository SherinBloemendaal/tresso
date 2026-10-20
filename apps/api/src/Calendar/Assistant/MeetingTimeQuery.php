<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

/**
 * What to find time for: a duration in a window, with whom, and how to propose it.
 *
 * Planning a todo is {@see self::beforeDeadline()}: the earliest free stretch of its duration before the deadline.
 */
final readonly class MeetingTimeQuery
{
    /** The longest window one search looks through. */
    public const string MAX_WINDOW = 'P92D';
    public const int MIN_DURATION_MINUTES = 5;
    public const int MAX_DURATION_MINUTES = 1440;
    public const int MAX_SLOTS = 50;
    /** Slot starts are on these multiples of minutes past the hour in the user's zone. */
    public const array STEPS = [5, 10, 15, 20, 30, 60];

    /**
     * @param list<MeetingAttendee> $attendees   besides the user
     * @param list<string>|null     $calendarIds the calendars whose busy time counts; null for the visible ones
     * @param list<string>          $ignore      busy time to leave out, by reference (an event or occurrence id)
     *
     * @throws InvalidMeetingTimeQuery when a value is outside its limits
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
            throw new InvalidMeetingTimeQuery('The end of the window must be after its start.', 'to');
        }
        if ($to > $from->add(new \DateInterval(self::MAX_WINDOW))) {
            throw new InvalidMeetingTimeQuery('The window can be at most 92 days.', 'to');
        }
        if ($durationMinutes < self::MIN_DURATION_MINUTES || $durationMinutes > self::MAX_DURATION_MINUTES) {
            throw new InvalidMeetingTimeQuery('The duration must be between 5 minutes and 24 hours.', 'durationMinutes');
        }
        if (!\in_array($stepMinutes, self::STEPS, true)) {
            throw new InvalidMeetingTimeQuery('The step must be 5, 10, 15, 20, 30 or 60 minutes.', 'stepMinutes');
        }
        if ($limit < 1 || $limit > self::MAX_SLOTS) {
            throw new InvalidMeetingTimeQuery('Ask for between 1 and 50 slots.', 'limit');
        }
        if (null !== $perDay && ($perDay < 1 || $perDay > self::MAX_SLOTS)) {
            throw new InvalidMeetingTimeQuery('Ask for between 1 and 50 slots a day.', 'perDay');
        }
    }

    /**
     * The earliest free stretch of a todo's duration from now until its deadline, in the user's working hours. A
     * deadline more than 92 days away is looked for in the next 92 days: plan again later when nothing is free there.
     * `ignore` holds the todo's own planned time when it is planned again.
     *
     * @param list<string> $ignore
     *
     * @throws InvalidMeetingTimeQuery when the deadline has passed, or the duration is outside 5 minutes to 24 hours
     */
    public static function beforeDeadline(\DateTimeImmutable $now, \DateTimeImmutable $deadline, int $durationMinutes, array $ignore = [], ?\DateTimeZone $timeZone = null): self
    {
        if ($deadline <= $now) {
            throw new InvalidMeetingTimeQuery('The deadline has passed.', 'to');
        }
        $to = min($deadline, $now->add(new \DateInterval(self::MAX_WINDOW)));

        return new self($now, $to, $durationMinutes, limit: 1, ignore: $ignore, timeZone: $timeZone);
    }

    /**
     * @return list<string>
     */
    public function attendeeAddresses(): array
    {
        return array_map(static fn (MeetingAttendee $attendee): string => $attendee->address, $this->attendees);
    }
}
