<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Calendar;

use App\Calendar\Assistant\MeetingTimeQuery;
use Symfony\Component\Validator\Constraints as Assert;

final class MeetingTimesInput
{
    /** Start of the window (RFC 3339). */
    #[Assert\NotBlank]
    #[Assert\DateTime(format: \DATE_ATOM, message: 'Use a date-time such as 2026-10-19T09:00:00+02:00.')]
    public string $from = '';

    /** End of the window (RFC 3339), at most 92 days after from. */
    #[Assert\NotBlank]
    #[Assert\DateTime(format: \DATE_ATOM, message: 'Use a date-time such as 2026-10-23T17:00:00+02:00.')]
    public string $to = '';

    #[Assert\Range(min: 5, max: MeetingTimeQuery::MAX_DURATION_MINUTES)]
    public int $durationMinutes = 30;

    /** @var list<MeetingAttendeeInput> */
    #[Assert\Count(max: 100)]
    #[Assert\Valid]
    public array $attendees = [];

    /** Slots start on multiples of this many minutes past the hour. */
    #[Assert\Choice(choices: MeetingTimeQuery::STEPS)]
    public int $stepMinutes = 15;

    #[Assert\Range(min: 1, max: MeetingTimeQuery::MAX_SLOTS)]
    public int $limit = 10;

    /** At most this many slots on one day; null for no limit. */
    #[Assert\Range(min: 1, max: MeetingTimeQuery::MAX_SLOTS)]
    public ?int $perDay = null;

    /** The meeting is at a place: travel time is kept free around the busy time next to it. */
    public bool $inPerson = false;

    /** false searches the whole window, outside working hours too. */
    public bool $workingHoursOnly = true;

    /** @var list<string>|null the calendars whose busy time counts; null for the visible ones */
    #[Assert\Count(max: 100)]
    #[Assert\All([new Assert\Uuid()])]
    public ?array $calendarIds = null;

    /** @var list<string> events (all occurrences) or occurrences (`eventId:recurrenceId`) to treat as free, such as the meeting being moved */
    #[Assert\Count(max: 100)]
    #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 100)])]
    public array $ignore = [];

    /** The zone of working hours and all-day events; the user's own when omitted. */
    #[Assert\Timezone]
    public ?string $timeZone = null;
}
