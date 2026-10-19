<?php

declare(strict_types=1);

namespace App\Calendar\ICalendar;

use App\Enum\EventStatus;
use App\Enum\EventVisibility;
use App\Enum\ParticipationStatus;
use App\Enum\Transparency;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Property\ICalendar\Duration as DurationProperty;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Recur\MaxInstancesExceededException;
use Sabre\VObject\TimeZoneUtil;

/**
 * Reads one VEVENT into {@see EventData}: time zones resolved to IANA names (Windows names and custom VTIMEZONEs
 * included), DURATION turned into an end, recurrence reduced to its rule plus occurrence keys, and the end of the
 * series computed for range queries.
 *
 * @phpstan-import-type Address from EventData
 * @phpstan-import-type Attendee from EventData
 * @phpstan-import-type Reminder from EventData
 */
final class EventReader
{
    /** Series longer than this are treated as endless for range queries, which only widens them. */
    private const int MAX_COUNTED_OCCURRENCES = 20000;

    /**
     * @param list<string> $selfAddresses the owner's addresses, lower case, to find their own response
     */
    public static function read(VEvent $event, VCalendar $calendar, array $selfAddresses): EventData
    {
        $utc = new \DateTimeZone('UTC');
        $uid = Props::uid($event);
        if ('' === $uid) {
            throw new InvalidCalendarData('An event has no UID.');
        }
        $dtStart = Props::dateTime($event, 'DTSTART');
        if (null === $dtStart) {
            throw new InvalidCalendarData(\sprintf('Event %s has no start.', $uid));
        }

        $start = self::time($dtStart, $calendar);
        $end = self::end($event, $start, $calendar);
        $startsAt = $start->toInstant($utc)->setTimezone($utc);
        $endsAt = $end->toInstant($utc)->setTimezone($utc);
        if ($endsAt < $startsAt) {
            $end = $start;
            $endsAt = $startsAt;
        }

        $recurrenceKey = CalendarDocument::recurrenceKey($event);

        $rrule = '' === $recurrenceKey ? Props::text($event, 'RRULE') : null;
        $rdates = '' === $recurrenceKey ? self::keys($event, 'RDATE') : [];
        $exdates = '' === $recurrenceKey ? self::keys($event, 'EXDATE') : [];
        $recurring = null !== $rrule || [] !== $rdates;

        $organizer = self::organizer($event);
        $attendees = self::attendees($event);

        return new EventData(
            $uid,
            $recurrenceKey,
            max(0, Props::int($event, 'SEQUENCE')),
            EventStatus::fromICalendar(self::text($event, 'STATUS')),
            self::text($event, 'SUMMARY') ?? '',
            self::text($event, 'DESCRIPTION') ?? '',
            self::text($event, 'LOCATION') ?? '',
            self::url($event),
            Transparency::fromICalendar(self::text($event, 'TRANSP')),
            EventVisibility::fromICalendar(self::text($event, 'CLASS')),
            $start,
            $end,
            $startsAt,
            $endsAt,
            $recurring ? $rrule : null,
            $rdates,
            $exdates,
            $recurring ? self::seriesEnd($event, $endsAt->getTimestamp() - $startsAt->getTimestamp()) : $endsAt,
            $organizer,
            $attendees,
            self::selfPartstat($organizer, $attendees, $selfAddresses),
            self::reminders($event),
            ConferenceDetector::detect($event),
        );
    }

    public static function time(DateTimeProperty $property, VCalendar $calendar): EventTime
    {
        if (!$property->hasTime()) {
            return EventTime::date($property->getDateTime(new \DateTimeZone('UTC')));
        }
        if ($property->isFloating()) {
            return EventTime::dateTime($property->getDateTime(new \DateTimeZone('UTC')), null);
        }

        $value = $property->getDateTime(new \DateTimeZone('UTC'));
        $tzid = Props::param($property, 'TZID');
        if (null !== $tzid) {
            $value = $value->setTimezone(TimeZoneUtil::getTimeZone($tzid, $calendar));
        }
        $name = $value->getTimezone()->getName();
        if ('Z' === $name || !self::isIanaZone($name)) {
            // A fixed offset that matches no known zone: keep the instant, in UTC.
            $value = $value->setTimezone(new \DateTimeZone('UTC'));
            $name = 'UTC';
        }

        return EventTime::dateTime($value, $name);
    }

    public static function isIanaZone(string $name): bool
    {
        return 'UTC' === $name || \in_array($name, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true);
    }

    private static function end(VEvent $event, EventTime $start, VCalendar $calendar): EventTime
    {
        $dtEnd = Props::dateTime($event, 'DTEND');
        if (null !== $dtEnd && $dtEnd->hasTime() === !$start->isDate) {
            return self::time($dtEnd, $calendar);
        }

        $startValue = $start->toInstant(new \DateTimeZone($start->timeZone ?? 'UTC'));
        $duration = Props::get($event, 'DURATION');
        if ($duration instanceof DurationProperty) {
            $endValue = $startValue->add($duration->getDateInterval());
        } elseif (null !== $dtEnd) {
            // DTEND of another kind than DTSTART (date against date-time): keep the length it describes.
            $endValue = $startValue->add($start->isDate ? new \DateInterval('P1D') : new \DateInterval('PT0S'));
            $other = $dtEnd->getDateTime(new \DateTimeZone($start->timeZone ?? 'UTC'));
            if ($other > $startValue) {
                $endValue = $start->isDate ? $other->setTime(0, 0) : $other;
            }
        } else {
            // RFC 5545 3.6.1: a date start without end lasts one day, a date-time start without end takes no time.
            $endValue = $start->isDate ? $startValue->add(new \DateInterval('P1D')) : $startValue;
        }

        return $start->isDate ? EventTime::date($endValue) : EventTime::dateTime($endValue, $start->timeZone);
    }

    /**
     * End of the last occurrence, or null when the series does not end (or has more occurrences than are worth
     * counting).
     */
    private static function seriesEnd(VEvent $master, int $lengthSeconds): ?\DateTimeImmutable
    {
        try {
            $iterator = new EventIterator([$master], null, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
        if ($iterator->isInfinite()) {
            return null;
        }

        $last = null;
        $count = 0;
        try {
            while ($iterator->valid()) {
                $last = $iterator->getDtStart();
                if (++$count > self::MAX_COUNTED_OCCURRENCES) {
                    return null;
                }
                $iterator->next();
            }
        } catch (MaxInstancesExceededException) {
            return null;
        }

        return null === $last ? null : \DateTimeImmutable::createFromInterface($last)->setTimezone(new \DateTimeZone('UTC'))->modify(\sprintf('+%d seconds', $lengthSeconds));
    }

    /**
     * @return list<string>
     */
    private static function keys(VEvent $event, string $name): array
    {
        $keys = [];
        foreach (Props::dateTimes($event, $name) as $property) {
            array_push($keys, ...RecurrenceKey::allFromProperty($property));
        }
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * @return Address|null
     */
    private static function organizer(VEvent $event): ?array
    {
        $organizer = Props::get($event, 'ORGANIZER');
        if (null === $organizer) {
            return null;
        }
        $address = self::address(Props::value($organizer));

        return null === $address ? null : ['address' => $address, 'name' => self::param($organizer, 'CN')];
    }

    /**
     * @return list<Attendee>
     */
    private static function attendees(VEvent $event): array
    {
        $attendees = [];
        foreach (Props::all($event, 'ATTENDEE') as $attendee) {
            $address = self::address(Props::value($attendee));
            if (null === $address || isset($attendees[$address])) {
                continue;
            }
            $attendees[$address] = [
                'address' => $address,
                'name' => self::param($attendee, 'CN'),
                'role' => match (strtoupper((string) self::param($attendee, 'ROLE'))) {
                    'CHAIR' => 'chair',
                    'OPT-PARTICIPANT' => 'optional',
                    'NON-PARTICIPANT' => 'non-participant',
                    default => 'required',
                },
                'partstat' => ParticipationStatus::fromICalendar(self::param($attendee, 'PARTSTAT'))->value,
                'rsvp' => 'TRUE' === strtoupper((string) self::param($attendee, 'RSVP')),
                'kind' => match (strtoupper((string) self::param($attendee, 'CUTYPE'))) {
                    'GROUP' => 'group',
                    'RESOURCE' => 'object',
                    'ROOM' => 'room',
                    'UNKNOWN' => 'unknown',
                    default => 'individual',
                },
            ];
        }

        return array_values($attendees);
    }

    /**
     * @param Address|null   $organizer
     * @param list<Attendee> $attendees
     * @param list<string>   $selfAddresses
     */
    private static function selfPartstat(?array $organizer, array $attendees, array $selfAddresses): ?ParticipationStatus
    {
        if (null !== $organizer && \in_array($organizer['address'], $selfAddresses, true)) {
            return null;
        }
        foreach ($attendees as $attendee) {
            if (\in_array($attendee['address'], $selfAddresses, true)) {
                return ParticipationStatus::from($attendee['partstat']);
            }
        }

        return null;
    }

    /**
     * @return list<Reminder>
     */
    private static function reminders(VEvent $event): array
    {
        $reminders = [];
        foreach (Props::components($event, 'VALARM') as $alarm) {
            $trigger = Props::get($alarm, 'TRIGGER');
            if (null === $trigger) {
                continue;
            }
            $action = strtolower(Props::text($alarm, 'ACTION') ?? 'DISPLAY');
            if ($trigger instanceof DateTimeProperty) {
                $reminders[] = ['action' => $action, 'trigger' => null, 'relatedTo' => 'start', 'at' => RecurrenceKey::fromInstant($trigger->getDateTime(new \DateTimeZone('UTC')))];
                continue;
            }
            $reminders[] = [
                'action' => $action,
                'trigger' => strtoupper(trim(Props::value($trigger))),
                'relatedTo' => 'END' === strtoupper((string) self::param($trigger, 'RELATED')) ? 'end' : 'start',
                'at' => null,
            ];
        }

        return $reminders;
    }

    private static function address(string $value): ?string
    {
        $value = trim($value);
        if (str_starts_with(strtolower($value), 'mailto:')) {
            $value = substr($value, 7);
        }
        $value = mb_strtolower(rawurldecode($value));

        return false === filter_var($value, \FILTER_VALIDATE_EMAIL) ? null : $value;
    }

    private static function url(VEvent $event): ?string
    {
        $url = self::text($event, 'URL');

        return null !== $url && 1 === preg_match('~^https?://~i', $url) && \strlen($url) <= 2048 ? $url : null;
    }

    private static function text(VEvent $event, string $name): ?string
    {
        return Props::text($event, $name);
    }

    private static function param(Property $property, string $name): ?string
    {
        return Props::param($property, $name);
    }
}
