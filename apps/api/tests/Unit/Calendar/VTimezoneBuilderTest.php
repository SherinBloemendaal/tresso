<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calendar;

use App\Calendar\ICalendar\CalendarDocument;
use App\Calendar\ICalendar\VTimezoneBuilder;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;
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

    public function testSabreReadsTheZoneBack(): void
    {
        $calendar = CalendarDocument::create();
        VTimezoneBuilder::ensure($calendar, 'America/New_York', new \DateTimeImmutable('2026-10-19'));
        $parsed = Reader::read($calendar->serialize());
        self::assertInstanceOf(\Sabre\VObject\Component\VCalendar::class, $parsed);

        self::assertSame('America/New_York', TimeZoneUtil::getTimeZone('America/New_York', $parsed)->getName());
    }
}
