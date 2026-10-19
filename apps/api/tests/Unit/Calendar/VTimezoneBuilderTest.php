<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calendar;

use App\Calendar\ICalendar\CalendarDocument;
use App\Calendar\ICalendar\Props;
use App\Calendar\ICalendar\VTimezoneBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\RRuleIterator;
use Sabre\VObject\TimeZoneUtil;

final class VTimezoneBuilderTest extends TestCase
{
    public function testWritesTheTransitionsOfTheZone(): void
    {
        $calendar = CalendarDocument::create();
        VTimezoneBuilder::ensure($calendar, 'Europe/Amsterdam', new \DateTimeImmutable('2026-10-19'));
        $serialized = $calendar->serialize();

        self::assertStringContainsString("TZID:Europe/Amsterdam\r\n", $serialized);
        // Summer time ends on 25 October 2026 at 03:00 local time.
        self::assertStringContainsString("BEGIN:STANDARD\r\nDTSTART:20261025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\n", $serialized);
        self::assertStringContainsString("BEGIN:DAYLIGHT\r\nDTSTART:20270328T020000\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0200\r\n", $serialized);
    }

    public function testAZoneWithoutTransitionsGetsOneObservance(): void
    {
        $calendar = CalendarDocument::create();
        VTimezoneBuilder::ensure($calendar, 'Asia/Kolkata', new \DateTimeImmutable('2026-10-19'));

        self::assertStringContainsString("TZOFFSETFROM:+0530\r\nTZOFFSETTO:+0530\r\n", $calendar->serialize());
    }

    public function testAddsEachZoneOnceAndNoneForUtc(): void
    {
        $calendar = CalendarDocument::create();
        VTimezoneBuilder::ensure($calendar, 'Europe/Amsterdam', new \DateTimeImmutable('2026-10-19'));
        VTimezoneBuilder::ensure($calendar, 'Europe/Amsterdam', new \DateTimeImmutable('2030-01-01'));
        VTimezoneBuilder::ensure($calendar, 'UTC', new \DateTimeImmutable('2026-10-19'));

        self::assertCount(1, $calendar->select('VTIMEZONE'));
    }

    public function testTheCurrentRuleCoversEveryLaterYear(): void
    {
        $calendar = CalendarDocument::create();
        VTimezoneBuilder::ensure($calendar, 'Europe/Amsterdam', new \DateTimeImmutable('2026-10-19'));
        VTimezoneBuilder::ensure($calendar, 'America/New_York', new \DateTimeImmutable('2026-10-19'));
        $before = $calendar->serialize();
        VTimezoneBuilder::ensure($calendar, 'Europe/Amsterdam', new \DateTimeImmutable('2061-07-01'));

        self::assertSame($before, $calendar->serialize(), 'A zone with an open rule covers 2061 already.');
        self::assertStringContainsString("RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU\r\n", $before);
        self::assertStringContainsString("RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU\r\n", $before);
        self::assertStringContainsString("RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU\r\n", $before);
        self::assertStringContainsString("RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU\r\n", $before);
    }

    public function testAZoneThatStartsTooLateIsReplacedByOneReachingBack(): void
    {
        $calendar = CalendarDocument::create();
        VTimezoneBuilder::ensure($calendar, 'Europe/Amsterdam', new \DateTimeImmutable('2026-10-19'));
        VTimezoneBuilder::ensure($calendar, 'Europe/Amsterdam', new \DateTimeImmutable('2015-06-01'));

        self::assertCount(1, $calendar->select('VTIMEZONE'));
        $serialized = $calendar->serialize();
        self::assertStringContainsString("DTSTART:20150329T020000\r\n", $serialized);
        self::assertStringContainsString("DTSTART:20261025T030000\r\n", $serialized);
        self::assertStringContainsString('RRULE:FREQ=YEARLY', $serialized);
    }

    public function testAZoneFromElsewhereThatEndsTooSoonIsReplaced(): void
    {
        $calendar = CalendarDocument::parse(implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:x',
            'BEGIN:VTIMEZONE', 'TZID:Europe/Amsterdam',
            'BEGIN:STANDARD', 'DTSTART:20251026T030000', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'END:STANDARD',
            'END:VTIMEZONE', 'END:VCALENDAR', '',
        ]));

        VTimezoneBuilder::ensure($calendar, 'Europe/Amsterdam', new \DateTimeImmutable('2026-10-19'));

        self::assertCount(1, $calendar->select('VTIMEZONE'));
        self::assertStringContainsString("DTSTART:20251026T030000\r\n", $calendar->serialize(), 'The new zone reaches back as far as the old one.');
        self::assertStringContainsString('RRULE:FREQ=YEARLY', $calendar->serialize());
    }

    /**
     * The written zone gives PHP's offset on every month until 2090, read from its observances and their rules.
     */
    #[DataProvider('zones')]
    public function testTheWrittenZoneHoldsForDecades(string $zoneName, ?string $rule): void
    {
        $calendar = CalendarDocument::create();
        VTimezoneBuilder::ensure($calendar, $zoneName, new \DateTimeImmutable('2026-10-19'));
        $vtimezone = $calendar->select('VTIMEZONE')[0] ?? self::fail('No zone.');
        self::assertInstanceOf(Component::class, $vtimezone);
        if (null !== $rule) {
            self::assertStringContainsString('RRULE:'.$rule."\r\n", $calendar->serialize());
        }

        $onsets = self::onsets($vtimezone, 2091);
        $zone = new \DateTimeZone($zoneName);
        for ($year = 2026; $year <= 2090; ++$year) {
            for ($month = 1; $month <= 12; ++$month) {
                $moment = new \DateTimeImmutable(\sprintf('%d-%02d-15 12:00:00', $year, $month), new \DateTimeZone('UTC'));
                $written = null;
                foreach ($onsets as [$at, $offset]) {
                    if ($at <= $moment->getTimestamp()) {
                        $written = $offset;
                    }
                }
                self::assertSame($zone->getOffset($moment), $written, \sprintf('%s on %s', $zoneName, $moment->format('Y-m-d')));
            }
        }
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function zones(): iterable
    {
        yield 'Amsterdam' => ['Europe/Amsterdam', 'FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU'];
        yield 'Jerusalem, the Friday before the last Sunday of March' => ['Asia/Jerusalem', 'FREQ=YEARLY;BYMONTH=3;BYDAY=FR;BYMONTHDAY=23,24,25,26,27,28,29'];
        yield 'Santiago, the first Sunday from the 2nd' => ['America/Santiago', 'FREQ=YEARLY;BYMONTH=4;BYDAY=SU;BYMONTHDAY=2,3,4,5,6,7,8'];
        yield 'Lord Howe, half an hour' => ['Australia/Lord_Howe', null];
        yield 'Casablanca, no rule at all' => ['Africa/Casablanca', null];
    }

    /**
     * The moments each observance starts, with the offset it brings, sorted.
     *
     * @return list<array{int, int}>
     */
    private static function onsets(Component $vtimezone, int $untilYear): array
    {
        $onsets = [];
        foreach ($vtimezone->children() as $observance) {
            if (!$observance instanceof Component) {
                continue;
            }
            $from = self::seconds(Props::text($observance, 'TZOFFSETFROM') ?? '');
            $to = self::seconds(Props::text($observance, 'TZOFFSETTO') ?? '');
            $start = \DateTimeImmutable::createFromFormat('Ymd\THis', Props::text($observance, 'DTSTART') ?? '', new \DateTimeZone('UTC'));
            self::assertNotFalse($start);
            $starts = [$start];
            $rule = Props::text($observance, 'RRULE');
            if (null !== $rule) {
                $starts = [];
                foreach (new RRuleIterator($rule, $start) as $occurrence) {
                    self::assertInstanceOf(\DateTimeInterface::class, $occurrence);
                    if ((int) $occurrence->format('Y') > $untilYear) {
                        break;
                    }
                    $starts[] = $occurrence;
                }
            }
            foreach ($starts as $local) {
                $onsets[] = [$local->getTimestamp() - $from, $to];
            }
        }
        usort($onsets, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $onsets;
    }

    private static function seconds(string $offset): int
    {
        $sign = str_starts_with($offset, '-') ? -1 : 1;

        return $sign * ((int) substr($offset, 1, 2) * 3600 + (int) substr($offset, 3, 2) * 60);
    }

    public function testARuleMustCoverTheLastYearToo(): void
    {
        $changes = [];
        foreach (range(2027, 2057) as $year) {
            $changes[] = self::change($year, 10, true);
            if ($year < 2057) {
                $changes[] = self::change($year, 3, false);
            }
        }
        usort($changes, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);

        $rules = new \ReflectionMethod(VTimezoneBuilder::class, 'rules')->invoke(null, $changes, 2057);

        self::assertNull($rules, 'Summer time missing in the last year means the rule does not hold.');
    }

    public function testSabreReadsTheZoneBack(): void
    {
        $calendar = CalendarDocument::create();
        VTimezoneBuilder::ensure($calendar, 'America/New_York', new \DateTimeImmutable('2026-10-19'));
        $parsed = Reader::read($calendar->serialize());
        self::assertInstanceOf(\Sabre\VObject\Component\VCalendar::class, $parsed);

        self::assertSame('America/New_York', TimeZoneUtil::getTimeZone('America/New_York', $parsed)->getName());
    }

    /**
     * A change at 02:00 UTC on the last Sunday of the month: into summer time ($daylight) or back.
     *
     * @return array{ts: int, from: int, to: int, isdst: bool, abbr: string}
     */
    private static function change(int $year, int $month, bool $daylight): array
    {
        $day = new \DateTimeImmutable(\sprintf('last sunday of %d-%02d 02:00:00', $year, $month), new \DateTimeZone('UTC'));

        return ['ts' => $day->getTimestamp(), 'from' => $daylight ? 3600 : 7200, 'to' => $daylight ? 7200 : 3600, 'isdst' => $daylight, 'abbr' => $daylight ? 'CEST' : 'CET'];
    }
}
