<?php

declare(strict_types=1);

namespace App\Tests\Unit\Batch;

use App\Batch\BatchTimes;
use PHPUnit\Framework\TestCase;

final class BatchTimesTest extends TestCase
{
    public function testTheNextBatchIsTheFirstTimeAfterTheInstantInTheUsersZone(): void
    {
        $times = new BatchTimes(['16:00', '09:00'], new \DateTimeZone('Europe/Amsterdam'));

        // 07:30 UTC is 09:30 in Amsterdam in summer time: the 16:00 batch is next.
        self::assertSame('2026-07-01T14:00:00+00:00', $times->next(new \DateTimeImmutable('2026-07-01T07:30:00Z'))->format(\DATE_ATOM));
        self::assertSame('2026-07-01T07:00:00+00:00', $times->previous(new \DateTimeImmutable('2026-07-01T07:30:00Z'))->format(\DATE_ATOM));
    }

    public function testAfterTheLastBatchOfTheDayTheNextIsTomorrowsFirst(): void
    {
        $times = new BatchTimes(['09:00', '16:00'], new \DateTimeZone('Europe/Amsterdam'));

        self::assertSame('2026-07-02T07:00:00+00:00', $times->next(new \DateTimeImmutable('2026-07-01T20:00:00Z'))->format(\DATE_ATOM));
        self::assertSame('2026-06-30T14:00:00+00:00', $times->previous(new \DateTimeImmutable('2026-07-01T06:59:59Z'))->format(\DATE_ATOM));
    }

    public function testABatchTimeItselfIsThePreviousBatchNotTheNext(): void
    {
        $times = new BatchTimes(['09:00'], new \DateTimeZone('UTC'));
        $at = new \DateTimeImmutable('2026-07-01T09:00:00Z');

        self::assertEquals($at, $times->previous($at));
        self::assertSame('2026-07-02T09:00:00+00:00', $times->next($at)->format(\DATE_ATOM));
    }

    public function testTimesStayOnTheWallClockAcrossADaylightSavingChange(): void
    {
        $times = new BatchTimes(['09:00'], new \DateTimeZone('Europe/Amsterdam'));

        // The clocks go back on 25 October 2026: 09:00 is 07:00 UTC before and 08:00 UTC after.
        self::assertSame('2026-10-24T07:00:00+00:00', $times->next(new \DateTimeImmutable('2026-10-23T12:00:00Z'))->format(\DATE_ATOM));
        self::assertSame('2026-10-25T08:00:00+00:00', $times->next(new \DateTimeImmutable('2026-10-24T12:00:00Z'))->format(\DATE_ATOM));
    }

    public function testATimeTheSpringClockChangeSkipsGoesOutRightAfterTheGap(): void
    {
        // On 29 March 2026 Amsterdam's clocks jump from 02:00 to 03:00: there is no 02:30 that day.
        $times = new BatchTimes(['02:30'], new \DateTimeZone('Europe/Amsterdam'));

        $batch = $times->next(new \DateTimeImmutable('2026-03-28T23:00:00Z'));

        self::assertSame('2026-03-29T01:30:00+00:00', $batch->format(\DATE_ATOM), '03:30 summer time, an hour after the gap began.');
        self::assertEquals($batch, $times->previous($batch));
        self::assertSame('2026-03-30T00:30:00+00:00', $times->next($batch)->format(\DATE_ATOM));
    }

    public function testATimeTheAutumnClockChangeRepeatsGoesOutOnce(): void
    {
        // On 25 October 2026 Amsterdam's clocks go back from 03:00 to 02:00: 02:30 happens twice.
        $times = new BatchTimes(['02:30'], new \DateTimeZone('Europe/Amsterdam'));

        $first = $times->next(new \DateTimeImmutable('2026-10-24T22:00:00Z'));

        self::assertSame('2026-10-25T00:30:00+00:00', $first->format(\DATE_ATOM), 'The first 02:30, in summer time.');
        self::assertSame('2026-10-26T01:30:00+00:00', $times->next($first)->format(\DATE_ATOM), 'Not again an hour later.');
    }

    public function testTimesAreSortedAndDeduplicatedAndAScheduleNeedsOne(): void
    {
        self::assertSame(['08:00', '13:00'], BatchTimes::normalize(['13:00', '08:00', '13:00', '25:00']));

        $this->expectException(\InvalidArgumentException::class);
        new BatchTimes([], new \DateTimeZone('UTC'));
    }

    public function testTheDefaultsAreTwoBatchesInUtc(): void
    {
        $defaults = BatchTimes::defaults();

        self::assertSame(['09:00', '16:00'], $defaults->times);
        self::assertSame('UTC', $defaults->timeZone->getName());
        self::assertTrue(BatchTimes::isTimeZone('Europe/Amsterdam'));
        self::assertTrue(BatchTimes::isTimeZone('Asia/Calcutta'), 'An alias browsers still report.');
        self::assertTrue(BatchTimes::isTimeZone('UTC'));
        self::assertFalse(BatchTimes::isTimeZone('Mars/Olympus'));
        self::assertFalse(BatchTimes::isTimeZone('+02:00'));
    }
}
