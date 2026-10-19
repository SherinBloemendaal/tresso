<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calendar;

use App\Entity\NotificationPreferences;
use App\Entity\User;
use App\Notification\QuietHours;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuietHoursTest extends TestCase
{
    /**
     * @param list<int> $days
     */
    #[DataProvider('moments')]
    public function testFindsTheEndOfTheQuietHours(array $days, string $start, string $end, string $moment, ?string $expectedEnd): void
    {
        $preferences = new NotificationPreferences(new User('kim@example.test', 'Kim'));
        $preferences->setTimeZone('Europe/Amsterdam');
        $preferences->setQuietHours(true, $days, $start, $end);

        $result = QuietHours::endOf($preferences, new \DateTimeImmutable($moment));

        self::assertSame($expectedEnd, $result?->format('Y-m-d H:i'));
    }

    /**
     * @return iterable<string, array{list<int>, string, string, string, ?string}>
     */
    public static function moments(): iterable
    {
        yield 'late evening' => [[1, 2, 3, 4, 5, 6, 7], '22:00', '07:00', '2026-10-19T21:30:00+00:00', '2026-10-20 07:00'];
        yield 'after midnight' => [[1, 2, 3, 4, 5, 6, 7], '22:00', '07:00', '2026-10-20T03:00:00+00:00', '2026-10-20 07:00'];
        yield 'daytime' => [[1, 2, 3, 4, 5, 6, 7], '22:00', '07:00', '2026-10-20T10:00:00+00:00', null];
        yield 'after midnight of a quiet Friday night' => [[5], '22:00', '07:00', '2026-10-24T03:00:00+00:00', '2026-10-24 07:00'];
        yield 'a night that is not quiet' => [[5], '22:00', '07:00', '2026-10-20T21:30:00+00:00', null];
        yield 'within one day' => [[6, 7], '12:00', '14:00', '2026-10-24T11:30:00+00:00', '2026-10-24 14:00'];
    }

    public function testNothingIsQuietWhenSwitchedOff(): void
    {
        $preferences = new NotificationPreferences(new User('kim@example.test', 'Kim'));

        self::assertNull(QuietHours::endOf($preferences, new \DateTimeImmutable('2026-10-19T23:00:00+00:00')));
    }
}
