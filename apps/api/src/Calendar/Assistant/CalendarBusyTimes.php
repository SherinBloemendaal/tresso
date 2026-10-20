<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

use App\ApiResource\CalendarOccurrence;
use App\Calendar\OccurrenceExpander;
use App\Entity\Calendar;
use App\Entity\User;
use App\Repository\CalendarRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Busy time from the user's calendars, recurrence expanded: occurrences marked busy that are not cancelled and that
 * the user did not decline. Tentative and unanswered invitations count as busy, as they do in the calendar views. A
 * timed busy event of the user's own titled "Focus..." is a focus block, as the reminders read it; an event's own
 * travel time (set for leave-now) is its travel time here too.
 */
final readonly class CalendarBusyTimes implements BusyTimeSource
{
    /** A location that only names the meeting service of an online meeting is not a place to travel to. */
    private const string ONLINE_LOCATION = '/\b(teams|zoom|meet|webex|skype|online|virtual)\b/i';

    public function __construct(
        private OccurrenceExpander $expander,
        private CalendarRepository $calendars,
        private Connection $connection,
    ) {
    }

    public function busyTimes(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $zone, ?array $calendarIds): iterable
    {
        $ids = $calendarIds ?? array_values(array_map(
            static fn (Calendar $calendar): string => $calendar->getId()->toRfc4122(),
            array_filter($this->calendars->findOwnedBy($user), static fn (Calendar $calendar): bool => $calendar->isVisible()),
        ));
        // The expander reads an empty list as every calendar.
        if ([] === $ids) {
            return [];
        }

        $occurrences = $this->expander->expand($user, $ids, $from, $to, $zone);
        $travel = $this->travelMinutes(array_values(array_unique(array_map(static fn (CalendarOccurrence $o): string => $o->eventId, $occurrences))));
        $busy = [];
        foreach ($occurrences as $occurrence) {
            $time = self::fromOccurrence($occurrence, $zone, $travel[$occurrence->eventId] ?? null);
            if (null !== $time) {
                $busy[] = $time;
            }
        }

        return $busy;
    }

    /**
     * The busy time an occurrence takes, or null when it leaves the user free. An all-day occurrence takes its days
     * from midnight to midnight in $zone.
     *
     * @param int|null $travelMinutes the event's own travel time
     */
    public static function fromOccurrence(CalendarOccurrence $occurrence, \DateTimeZone $zone, ?int $travelMinutes = null): ?BusyTime
    {
        if ('busy' !== $occurrence->transparency || 'cancelled' === $occurrence->status || 'declined' === $occurrence->selfResponse) {
            return null;
        }

        return new BusyTime(
            self::instant($occurrence->start, $zone),
            self::instant($occurrence->end, $zone),
            $occurrence->summary,
            [$occurrence->eventId, $occurrence->id],
            self::atPlace($occurrence->location, $occurrence->conference),
            $travelMinutes,
            self::isFocusBlock($occurrence),
        );
    }

    /**
     * A focus block as the reminders read it: timed, with no one else, titled "Focus...".
     */
    public static function isFocusBlock(CalendarOccurrence $occurrence): bool
    {
        return !$occurrence->allDay && 0 === $occurrence->attendeeCount && str_starts_with(mb_strtolower(trim($occurrence->summary)), 'focus');
    }

    /**
     * Whether the event is somewhere the user has to go: it has a location that is not a link, and that does not
     * just name the service of its online meeting ("Microsoft Teams Meeting").
     *
     * @param array{provider: string, url: string, id: ?string, dialIn: ?string}|null $conference
     */
    public static function atPlace(string $location, ?array $conference): bool
    {
        $location = trim($location);
        if ('' === $location || 1 === preg_match('~^[a-z][a-z0-9+.-]*://~i', $location)) {
            return false;
        }

        return null === $conference || 1 !== preg_match(self::ONLINE_LOCATION, $location);
    }

    /**
     * The travel time events name themselves, by event id.
     *
     * @param list<string> $eventIds
     *
     * @return array<string, int>
     */
    private function travelMinutes(array $eventIds): array
    {
        if ([] === $eventIds) {
            return [];
        }
        /** @var array<string, int|string> $minutes */
        $minutes = $this->connection->fetchAllKeyValue(
            'SELECT id, travel_minutes FROM calendar_event WHERE id IN (:ids) AND travel_minutes IS NOT NULL',
            ['ids' => $eventIds],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_map(intval(...), $minutes);
    }

    /**
     * @param array{date: string}|array{dateTime: string} $time
     */
    private static function instant(array $time, \DateTimeZone $zone): \DateTimeImmutable
    {
        return isset($time['date']) ? new \DateTimeImmutable($time['date'].' 00:00', $zone) : new \DateTimeImmutable($time['dateTime']);
    }
}
