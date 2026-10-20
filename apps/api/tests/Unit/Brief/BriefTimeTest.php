<?php

declare(strict_types=1);

namespace App\Tests\Unit\Brief;

use App\Brief\BriefTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BriefTimeTest extends TestCase
{
    #[DataProvider('runs')]
    public function testTheNextRunIsTheFirstDeliveryTimeAfterNow(string $deliverAt, string $zone, string $now, string $expected): void
    {
        $next = BriefTime::nextRun($deliverAt, $zone, new \DateTimeImmutable($now));

        self::assertSame($expected, $next->format(\DATE_ATOM));
        self::assertSame('UTC', $next->getTimezone()->getName());
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function runs(): iterable
    {
        yield 'later today' => ['08:00', 'UTC', '2026-10-20T06:00:00+00:00', '2026-10-20T08:00:00+00:00'];
        yield 'tomorrow once passed' => ['08:00', 'UTC', '2026-10-20T09:00:00+00:00', '2026-10-21T08:00:00+00:00'];
        yield 'exactly now is tomorrow' => ['08:00', 'UTC', '2026-10-20T08:00:00+00:00', '2026-10-21T08:00:00+00:00'];
        yield 'read in the users zone' => ['07:30', 'Europe/Amsterdam', '2026-10-20T04:00:00+00:00', '2026-10-20T05:30:00+00:00'];
        yield 'a date line ahead' => ['08:00', 'Pacific/Auckland', '2026-10-20T20:00:00+00:00', '2026-10-21T19:00:00+00:00'];
        yield 'after summer time ends' => ['08:00', 'Europe/Amsterdam', '2026-10-24T08:00:00+00:00', '2026-10-25T07:00:00+00:00'];
        yield 'a time the clock skips' => ['02:30', 'Europe/Amsterdam', '2026-03-28T12:00:00+00:00', '2026-03-29T01:30:00+00:00'];
    }

    public function testTheRepeatedAutumnHourMakesNoSecondBrief(): void
    {
        // 02:30 comes twice in Amsterdam on 25 October 2026: at 00:30 UTC (summer time) and at 01:30 UTC (winter time).
        // The brief due at the first was made late, at 02:00 winter time, which is before the second 02:30.
        $lateSweep = new \DateTimeImmutable('2026-10-25T01:00:00+00:00');

        $next = BriefTime::nextRun('02:30', 'Europe/Amsterdam', $lateSweep, $lateSweep);

        self::assertSame('2026-10-26T01:30:00+00:00', $next->format(\DATE_ATOM));
    }

    public function testALaterTimeTheSameDayWaitsForTomorrowOnceTodaysBriefWasMade(): void
    {
        $madeAt = new \DateTimeImmutable('2026-10-20T08:00:00+00:00');

        self::assertSame('2026-10-21T18:00:00+00:00', BriefTime::nextRun('18:00', 'UTC', new \DateTimeImmutable('2026-10-20T09:00:00+00:00'), $madeAt)->format(\DATE_ATOM));
        self::assertSame('2026-10-20T18:00:00+00:00', BriefTime::nextRun('18:00', 'UTC', new \DateTimeImmutable('2026-10-20T09:00:00+00:00'), $madeAt->modify('-1 day'))->format(\DATE_ATOM));
    }

    public function testAnotherZoneCountsTodayInThatZone(): void
    {
        // Made at 08:00 UTC, 04:00 in New York: 08:00 there comes later the same New York day, so it waits a day.
        $madeAt = new \DateTimeImmutable('2026-10-20T08:00:00+00:00');

        $next = BriefTime::nextRun('08:00', 'America/New_York', new \DateTimeImmutable('2026-10-20T09:00:00+00:00'), $madeAt);

        self::assertSame('2026-10-21T12:00:00+00:00', $next->format(\DATE_ATOM));
        self::assertTrue(BriefTime::ranToday($madeAt, 'America/New_York', new \DateTimeImmutable('2026-10-20T09:00:00+00:00')));
        self::assertFalse(BriefTime::ranToday($madeAt, 'Pacific/Auckland', new \DateTimeImmutable('2026-10-20T12:00:00+00:00')));
        self::assertFalse(BriefTime::ranToday(null, 'UTC', $madeAt));
    }

    public function testATimeThatIsNoTimeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BriefTime::nextRun('8:00', 'UTC', new \DateTimeImmutable());
    }
}
