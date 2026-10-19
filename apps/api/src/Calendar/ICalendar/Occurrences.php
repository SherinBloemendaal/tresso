<?php

declare(strict_types=1);

namespace App\Calendar\ICalendar;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Recur\EventIterator;

/**
 * Turning one occurrence of a series into its own VEVENT (an override), the way RFC 5545 models a changed occurrence.
 */
final class Occurrences
{
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
