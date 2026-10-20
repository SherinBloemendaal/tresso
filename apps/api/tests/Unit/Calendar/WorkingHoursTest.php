<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calendar;

use App\Entity\NotificationPreferences;
use App\Entity\User;
use App\Notification\WorkingHours;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkingHoursTest extends TestCase
{
    #[DataProvider('earlyReminders')]
    public function testMovesEarlyRemindersToTheEndOfTheWorkingDayBefore(string $fireAt, string $startsAt, ?string $expected): void
    {
        $result = WorkingHours::shiftEarly(self::preferences(), new \DateTimeImmutable($fireAt), new \DateTimeImmutable($startsAt));

        self::assertSame($expected, $result?->format('D Y-m-d H:i'));
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function earlyReminders(): iterable
    {
        // Monday to Friday, 09:00 to 17:30 in Amsterdam (UTC+2 in October).
        yield 'an 08:00 meeting on Wednesday' => ['2026-10-21T05:50:00+00:00', '2026-10-21T06:00:00+00:00', 'Tue 2026-10-20 17:30'];
        yield 'an 08:00 meeting on Monday goes to Friday' => ['2026-10-19T05:50:00+00:00', '2026-10-19T06:00:00+00:00', 'Fri 2026-10-16 17:30'];
        yield 'a reminder within working hours stays' => ['2026-10-21T07:50:00+00:00', '2026-10-21T08:00:00+00:00', null];
        yield 'an event after the working day starts stays' => ['2026-10-21T05:00:00+00:00', '2026-10-21T09:00:00+00:00', null];
        yield 'a Saturday stays' => ['2026-10-24T05:50:00+00:00', '2026-10-24T06:00:00+00:00', null];
    }

    public function testKnowsWhenWorkingHoursAreAndStartNext(): void
    {
        $preferences = self::preferences();

        self::assertTrue(WorkingHours::contains($preferences, new \DateTimeImmutable('2026-10-21T10:00:00+00:00')));
        self::assertFalse(WorkingHours::contains($preferences, new \DateTimeImmutable('2026-10-21T16:00:00+00:00')));
        self::assertSame('Mon 2026-10-26 09:00', WorkingHours::nextStart($preferences, new \DateTimeImmutable('2026-10-23T16:00:00+00:00'))?->format('D Y-m-d H:i'));
        $preferences->setWorkingHours([], '09:00', '17:30');
        self::assertNull(WorkingHours::nextStart($preferences, new \DateTimeImmutable('2026-10-23T16:00:00+00:00')));
    }

    public function testWorkingHoursPastMidnightRunIntoTheNextDay(): void
    {
        $preferences = self::preferences();
        // A night shift from Monday to Friday, 22:00 to 06:00 in Amsterdam (UTC+2 in October).
        $preferences->setWorkingHours([1, 2, 3, 4, 5], '22:00', '06:00');

        self::assertTrue(WorkingHours::contains($preferences, new \DateTimeImmutable('2026-10-20T01:00:00+00:00')), 'Tuesday 03:00 is in Monday\'s shift.');
        self::assertTrue(WorkingHours::contains($preferences, new \DateTimeImmutable('2026-10-24T01:00:00+00:00')), 'Saturday 03:00 is in Friday\'s shift.');
        self::assertFalse(WorkingHours::contains($preferences, new \DateTimeImmutable('2026-10-19T01:00:00+00:00')), 'Monday 03:00 follows a free Sunday.');
        self::assertFalse(WorkingHours::contains($preferences, new \DateTimeImmutable('2026-10-20T10:00:00+00:00')));
        self::assertSame('Mon 2026-10-19 22:00', WorkingHours::nextStart($preferences, new \DateTimeImmutable('2026-10-19T10:00:00+00:00'))?->format('D Y-m-d H:i'));
        self::assertSame('Mon 2026-10-26 22:00', WorkingHours::nextStart($preferences, new \DateTimeImmutable('2026-10-24T10:00:00+00:00'))?->format('D Y-m-d H:i'));
        // A reminder at 21:50 for a 21:55 event moves to the end of the shift that morning.
        self::assertSame('Wed 2026-10-21 06:00', WorkingHours::shiftEarly($preferences, new \DateTimeImmutable('2026-10-21T19:50:00+00:00'), new \DateTimeImmutable('2026-10-21T19:55:00+00:00'))?->format('D Y-m-d H:i'));
    }

    public function testWorkingHoursUntilMidnightEndAtMidnight(): void
    {
        $preferences = self::preferences();
        $preferences->setWorkingHours([1, 2, 3, 4, 5], '09:00', '00:00');

        self::assertTrue(WorkingHours::contains($preferences, new \DateTimeImmutable('2026-10-21T21:30:00+00:00')));
        self::assertFalse(WorkingHours::contains($preferences, new \DateTimeImmutable('2026-10-21T22:30:00+00:00')));
    }

    private static function preferences(): NotificationPreferences
    {
        $preferences = new NotificationPreferences(new User('kim@example.test', 'Kim'));
        $preferences->setTimeZone('Europe/Amsterdam');

        return $preferences;
    }
}
