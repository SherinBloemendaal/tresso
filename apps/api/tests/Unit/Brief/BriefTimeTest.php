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

    public function testATimeThatIsNoTimeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BriefTime::nextRun('8:00', 'UTC', new \DateTimeImmutable());
    }

    public function testZonesAreTheKnownOnes(): void
    {
        self::assertTrue(BriefTime::isZone('Europe/Amsterdam'));
        self::assertTrue(BriefTime::isZone('UTC'));
        self::assertFalse(BriefTime::isZone('Mars/Olympus'));
        self::assertFalse(BriefTime::isZone('+02:00'));
    }
}
