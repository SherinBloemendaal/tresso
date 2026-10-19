<?php

declare(strict_types=1);

namespace App\Calendar\ICalendar;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Recur\NoInstancesException;

/**
 * The occurrences of one resource in a range, and turning one occurrence of a series into its own VEVENT (an
 * override), the way RFC 5545 models a changed occurrence.
 */
final class Occurrences
{
    /**
     * Whether a series repeats more often than hourly. sabre/vobject does not advance such rules reliably, and they
     * would produce more occurrences than anyone can use, so they are refused when stored.
     */
    public static function tooDense(VEvent $event): bool
    {
        $rule = Props::text($event, 'RRULE');

        return null !== $rule && 1 === preg_match('/(^|;)FREQ=(SECONDLY|MINUTELY)(;|$)/i', $rule);
    }

    /**
     * The instances of the resource's events that overlap [$from, $to), one at a time and at most $limit, as sabre's
     * VCalendar::expand() produces them but without building them all at once. Recurrence ids keep their zone.
     *
     * @return \Generator<int, VEvent>
     */
    public static function instances(VCalendar $calendar, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $zone, int $limit): \Generator
    {
        $count = 0;
        $series = [];
        foreach (CalendarDocument::events($calendar) as $event) {
            if (null !== Props::get($event, 'RECURRENCE-ID') || null !== Props::get($event, 'RRULE') || null !== Props::get($event, 'RDATE')) {
                $series[] = $event;
                continue;
            }
            if ($event->isInTimeRange($from, $to)) {
                yield $event;
                ++$count;
            }
        }
        if ([] === $series || $count >= $limit) {
            return;
        }
        foreach ($series as $event) {
            if (self::tooDense($event)) {
                return;
            }
        }

        try {
            $iterator = new EventIterator($series, null, $zone);
        } catch (NoInstancesException) {
            return;
        }
        $iterator->fastForward($from);
        while ($iterator->valid() && $iterator->getDtStart() < $to) {
            if ($iterator->getDtEnd() > $from) {
                yield $iterator->getEventObject();
                if (++$count >= $limit) {
                    return;
                }
            }
            $iterator->next();
        }
    }

    /**
     * The override for the occurrence: the existing one, or a new copy of the master at that occurrence, added to the
     * calendar.
     */
    public static function override(VCalendar $calendar, VEvent $master, string $key): VEvent
    {
        $existing = CalendarDocument::override($calendar, $key);
        if (null !== $existing) {
            return $existing;
        }

        $isDate = !(Props::dateTime($master, 'DTSTART')?->hasTime() ?? true);
        $iterator = new EventIterator([$master], null, new \DateTimeZone('UTC'));
        while ($iterator->valid()) {
            $start = $iterator->getDtStart();
            $current = $isDate ? RecurrenceKey::fromDate($start) : RecurrenceKey::fromInstant($start);
            if ($current === $key) {
                $instance = $iterator->getEventObject();
                unset($instance->VALARM);
                foreach (Props::components($master, 'VALARM') as $alarm) {
                    $instance->add(clone $alarm);
                }
                $calendar->add($instance);

                return $instance;
            }
            if ($current > $key) {
                break;
            }
            $iterator->next();
        }

        throw new InvalidCalendarData('The series has no occurrence at that time.');
    }
}
