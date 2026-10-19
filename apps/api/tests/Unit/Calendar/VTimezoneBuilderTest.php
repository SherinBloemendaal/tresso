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

    public function testSabreReadsTheZoneBack(): void
    {
        $calendar = CalendarDocument::create();
        VTimezoneBuilder::ensure($calendar, 'America/New_York', new \DateTimeImmutable('2026-10-19'));
        $parsed = Reader::read($calendar->serialize());
        self::assertInstanceOf(\Sabre\VObject\Component\VCalendar::class, $parsed);

        self::assertSame('America/New_York', TimeZoneUtil::getTimeZone('America/New_York', $parsed)->getName());
    }
}
