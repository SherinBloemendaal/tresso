<?php

declare(strict_types=1);

namespace App\Calendar\ICalendar;

use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VTimeZone;

/**
 * Adds the VTIMEZONE that RFC 5545 requires for every TZID an object uses, built from PHP's time zone database.
 * Transitions are written out for the years around the event; clients that know the IANA name (all common ones) use
 * their own rules anyway, and the written ones cover the rest.
 */
final class VTimezoneBuilder
{
    private const int YEARS_BEFORE = 1;
    private const int YEARS_AFTER = 10;

    public static function ensure(VCalendar $calendar, string $zoneName, \DateTimeImmutable $around): void
    {
        if ('UTC' === $zoneName) {
            return;
        }
        foreach (Props::components($calendar, 'VTIMEZONE') as $existing) {
            if (Props::text($existing, 'TZID') === $zoneName) {
                return;
            }
        }

        $zone = new \DateTimeZone($zoneName);
        $year = (int) $around->format('Y');
        $from = new \DateTimeImmutable(\sprintf('%d-01-01 00:00:00', $year - self::YEARS_BEFORE), new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable(\sprintf('%d-12-31 23:59:59', $year + self::YEARS_AFTER), new \DateTimeZone('UTC'));
        $transitions = $zone->getTransitions($from->getTimestamp(), $to->getTimestamp());
        if ([] === $transitions) {
            throw new \LogicException(\sprintf('No time zone data for %s.', $zoneName));
        }

        /** @var VTimeZone $vtimezone */
        $vtimezone = $calendar->createComponent('VTIMEZONE');
        $vtimezone->add('TZID', $zoneName);
        $vtimezone->add('X-LIC-LOCATION', $zoneName);

        // The first entry is the state at the start of the range; the others are the changes within it.
        $first = $transitions[0];
        if (1 === \count($transitions)) {
            $vtimezone->add(self::observance($calendar, $first['isdst'], $from->getTimestamp(), $first['offset'], $first['offset'], $first['abbr']));
        }
        for ($i = 1, $n = \count($transitions); $i < $n; ++$i) {
            $transition = $transitions[$i];
            $vtimezone->add(self::observance($calendar, $transition['isdst'], $transition['ts'], $transitions[$i - 1]['offset'], $transition['offset'], $transition['abbr']));
        }

        $calendar->add($vtimezone);
    }

    private static function observance(VCalendar $calendar, bool $daylight, int $timestamp, int $offsetFrom, int $offsetTo, string $name): Component
    {
        // DTSTART of an observance is the local time before the change.
        $local = new \DateTimeImmutable('@'.($timestamp + $offsetFrom));
        $component = $calendar->createComponent($daylight ? 'DAYLIGHT' : 'STANDARD');
        $component->add('DTSTART', $local->format('Ymd\THis'));
        $component->add('TZOFFSETFROM', self::offset($offsetFrom));
        $component->add('TZOFFSETTO', self::offset($offsetTo));
        $component->add('TZNAME', $name);

        return $component;
    }

    private static function offset(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);

        return \sprintf('%s%02d%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
