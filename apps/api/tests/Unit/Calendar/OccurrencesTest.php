<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calendar;

use App\Calendar\ICalendar\CalendarDocument;
use App\Calendar\ICalendar\Occurrences;
use App\Calendar\ICalendar\Props;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Recur\MaxInstancesExceededException;

final class OccurrencesTest extends TestCase
{
    public function testAFloatingEventIsReadInTheUsersZone(): void
    {
        $calendar = self::calendar([['DTSTART:20261019T090000', 'DTEND:20261019T100000']]);

        self::assertCount(1, self::instances($calendar, '2026-10-19T07:00:00Z', '2026-10-19T08:00:00Z', 'Europe/Amsterdam'));
        self::assertCount(0, self::instances($calendar, '2026-10-19T09:00:00Z', '2026-10-19T10:00:00Z', 'Europe/Amsterdam'));
    }

    public function testAnAllDayEventIsTheUsersDay(): void
    {
        $calendar = self::calendar([['DTSTART;VALUE=DATE:20261020', 'DTEND;VALUE=DATE:20261021']]);

        // 20 October in Auckland (+13:00) begins at 11:00 UTC on the 19th.
        self::assertCount(1, self::instances($calendar, '2026-10-19T12:00:00Z', '2026-10-19T13:00:00Z', 'Pacific/Auckland'));
    }

    public function testARuleAndExtraDatesMakeOneSeries(): void
    {
        $calendar = self::calendar([['DTSTART:20261019T090000Z', 'DTEND:20261019T093000Z', 'RRULE:FREQ=WEEKLY;COUNT=2', 'RDATE:20261021T090000Z', 'EXDATE:20261026T090000Z']]);

        self::assertSame(['20261019T090000Z', '20261021T090000Z'], self::starts($calendar, '2026-10-01T00:00:00Z', '2026-12-01T00:00:00Z'));
    }

    public function testExtraDatesComeInOrderEachInItsOwnZone(): void
    {
        $calendar = self::calendar([['DTSTART:20261019T090000Z', 'RDATE:20261105T090000Z', 'RDATE;TZID=America/New_York:20261101T090000', 'RDATE:20261020T090000Z']]);

        self::assertSame(['20261019T090000Z', '20261020T090000Z', '20261101T140000Z', '20261105T090000Z'], self::starts($calendar, '2026-10-01T00:00:00Z', '2026-12-01T00:00:00Z'));
        self::assertSame('20261101T140000Z', CalendarDocument::recurrenceKey(Occurrences::override($calendar, self::master($calendar), '20261101T140000Z')));
    }

    public function testAnOverrideForThisAndFutureChangesTheLaterOccurrences(): void
    {
        $calendar = self::calendar([
            ['DTSTART:20261019T090000Z', 'DTEND:20261019T093000Z', 'RRULE:FREQ=WEEKLY;COUNT=4', 'SUMMARY:Sync'],
            ['RECURRENCE-ID;RANGE=THISANDFUTURE:20261026T090000Z', 'DTSTART:20261026T110000Z', 'DTEND:20261026T120000Z', 'SUMMARY:Sync, later'],
        ]);

        $instances = [];
        foreach (Occurrences::instances($calendar, new \DateTimeImmutable('2026-10-01T00:00:00Z'), new \DateTimeImmutable('2026-12-01T00:00:00Z'), new \DateTimeZone('UTC'), 10) as $source => $instance) {
            $instances[] = [$source, Props::text($instance, 'SUMMARY'), Props::text($instance, 'DTSTART'), Props::text($instance, 'DTEND'), CalendarDocument::recurrenceKey($instance)];
        }

        self::assertSame([
            ['', 'Sync', '20261019T090000Z', '20261019T093000Z', '20261019T090000Z'],
            ['20261026T090000Z', 'Sync, later', '20261026T110000Z', '20261026T120000Z', '20261026T090000Z'],
            ['20261026T090000Z', 'Sync, later', '20261102T110000Z', '20261102T120000Z', '20261102T090000Z'],
            ['20261026T090000Z', 'Sync, later', '20261109T110000Z', '20261109T120000Z', '20261109T090000Z'],
        ], $instances);
    }

    public function testAnHourlySeriesFromLongAgoStillHasItsOccurrences(): void
    {
        $calendar = self::calendar([['DTSTART:20100101T000000Z', 'DTEND:20100101T003000Z', 'RRULE:FREQ=HOURLY']]);

        self::assertSame(['20261019T000000Z', '20261019T010000Z', '20261019T020000Z'], self::starts($calendar, '2026-10-19T00:00:00Z', '2026-10-19T03:00:00Z'));
        self::assertSame('20261019T020000Z', Props::text(Occurrences::override($calendar, self::master($calendar), '20261019T020000Z'), 'DTSTART'));
    }

    public function testAnHourlySeriesFromYearOneIsLookedThroughQuickly(): void
    {
        $calendar = self::calendar([['DTSTART:00010101T000000Z', 'DTEND:00010101T003000Z', 'RRULE:FREQ=HOURLY']]);
        $started = microtime(true);

        self::assertCount(24, self::instances($calendar, '2026-10-19T00:00:00Z', '2026-10-20T00:00:00Z'));
        self::assertSame('20261019T050000Z', Props::text(Occurrences::override($calendar, self::master($calendar), '20261019T050000Z'), 'DTSTART'));
        self::assertLessThan(1.0, microtime(true) - $started, 'The series is jumped to the range, not walked from year 1.');
    }

    public function testAWalkThatCannotBeJumpedStopsAtItsLimit(): void
    {
        // COUNT counts from the first occurrence, so this series cannot be jumped: the walk is cut off instead.
        $calendar = self::calendar([['DTSTART:00010101T000000Z', 'RRULE:FREQ=HOURLY;COUNT=100000000']]);
        $started = microtime(true);

        try {
            self::instances($calendar, '2026-10-19T00:00:00Z', '2026-10-20T00:00:00Z');
            self::fail('The walk should have been cut off.');
        } catch (MaxInstancesExceededException) {
        }
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function testAnAllDayOccurrenceLastsItsDaysOnAShortDay(): void
    {
        // 29 March 2026 has 23 hours in Amsterdam.
        $calendar = self::calendar([['DTSTART;VALUE=DATE:20260329', 'DTEND;VALUE=DATE:20260330', 'RRULE:FREQ=DAILY;COUNT=2']]);

        $ends = array_map(static fn (VEvent $instance): ?string => Props::text($instance, 'DTEND'), self::instances($calendar, '2026-03-01T00:00:00Z', '2026-04-01T00:00:00Z', 'Europe/Amsterdam'));

        self::assertSame(['20260330', '20260331'], $ends);
    }

    public function testAnOverrideWhoseOccurrenceIsGoneStillShows(): void
    {
        $calendar = self::calendar([
            ['DTSTART:20261019T090000Z', 'DTEND:20261019T093000Z', 'RRULE:FREQ=WEEKLY;COUNT=2'],
            ['RECURRENCE-ID:20261020T090000Z', 'DTSTART:20261020T100000Z', 'DTEND:20261020T103000Z'],
        ]);

        self::assertSame(['20261019T090000Z', '20261020T090000Z', '20261026T090000Z'], self::sorted(self::starts($calendar, '2026-10-01T00:00:00Z', '2026-12-01T00:00:00Z')));
    }

    public function testAnExtraDateInAnotherZoneTakesTheSeriesZone(): void
    {
        $calendar = self::calendar([['DTSTART;TZID=Europe/Amsterdam:20261019T090000', 'DTEND;TZID=Europe/Amsterdam:20261019T093000', 'RDATE;TZID=America/New_York:20261101T090000']]);

        $instance = self::instances($calendar, '2026-11-01T00:00:00Z', '2026-11-02T00:00:00Z')[0];
        $start = Props::dateTime($instance, 'DTSTART');
        self::assertNotNull($start);

        self::assertSame('Europe/Amsterdam', Props::param($start, 'TZID'), 'No zone the resource does not define.');
        self::assertSame('20261101T150000', Props::text($instance, 'DTSTART'));
    }

    public function testCountIsTakenBeforeExcludedDates(): void
    {
        $calendar = self::calendar([['DTSTART:20261019T090000Z', 'RRULE:FREQ=DAILY;COUNT=5', 'EXDATE:20261020T090000Z']]);

        self::assertSame(3, Occurrences::ruleOccurrencesBefore(self::master($calendar), '20261022T090000Z', new \DateTimeZone('UTC')));
    }

    public function testOccurrencesWithoutTheirSeriesAreListed(): void
    {
        $calendar = self::calendar([['RECURRENCE-ID:20261026T090000Z', 'DTSTART:20261026T100000Z', 'DTEND:20261026T110000Z']]);

        self::assertSame(['20261026T090000Z'], self::starts($calendar, '2026-10-01T00:00:00Z', '2026-12-01T00:00:00Z'));
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private static function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }

    /**
     * @param list<list<string>> $events
     */
    private static function calendar(array $events): VCalendar
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Test//EN'];
        foreach ($events as $properties) {
            $lines = [...$lines, 'BEGIN:VEVENT', 'UID:series@example', ...$properties, 'END:VEVENT'];
        }

        return CalendarDocument::parse(implode("\r\n", [...$lines, 'END:VCALENDAR', '']));
    }

    private static function master(VCalendar $calendar): VEvent
    {
        return CalendarDocument::events($calendar)[0];
    }

    /**
     * @return list<VEvent>
     */
    private static function instances(VCalendar $calendar, string $from, string $to, string $zone = 'UTC'): array
    {
        return iterator_to_array(Occurrences::instances($calendar, new \DateTimeImmutable($from), new \DateTimeImmutable($to), new \DateTimeZone($zone), 100), false);
    }

    /**
     * @return list<string>
     */
    private static function starts(VCalendar $calendar, string $from, string $to): array
    {
        return array_map(static fn (VEvent $instance): string => CalendarDocument::recurrenceKey($instance), self::instances($calendar, $from, $to));
    }
}
