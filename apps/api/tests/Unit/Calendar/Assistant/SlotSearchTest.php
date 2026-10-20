<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calendar\Assistant;

use App\Calendar\Assistant\AttendeeAvailability;
use App\Calendar\Assistant\BlockKind;
use App\Calendar\Assistant\BusyTime;
use App\Calendar\Assistant\InvalidMeetingTimeQuery;
use App\Calendar\Assistant\MeetingAttendee;
use App\Calendar\Assistant\MeetingTimeQuery;
use App\Calendar\Assistant\NoSlotReason;
use App\Calendar\Assistant\SchedulingRules;
use App\Calendar\Assistant\Slot;
use App\Calendar\Assistant\SlotSearch;
use App\Calendar\Assistant\SlotSearchResult;
use App\Calendar\Assistant\WeeklyTime;
use PHPUnit\Framework\TestCase;

final class SlotSearchTest extends TestCase
{
    private const string ZONE = 'Europe/Amsterdam';
    /** Long before every window in these tests, so the notice never matters unless a test sets it. */
    private const string NOW = '2026-01-01T00:00:00Z';

    public function testProposesTheEarliestTimesWithinWorkingHours(): void
    {
        // Monday 19 October 2026; Amsterdam is at +02:00.
        $result = self::search(self::query('2026-10-19T00:00:00Z', '2026-10-20T00:00:00Z', 60, limit: 3));

        self::assertSame(['2026-10-19T07:00:00+00:00', '2026-10-19T08:00:00+00:00', '2026-10-19T09:00:00+00:00'], self::starts($result));
        $first = $result->slots[0];
        self::assertSame(BlockKind::WorkingHours, $first->freeFrom->kind);
        self::assertSame(BlockKind::WorkingHours, $first->freeUntil->kind);
        self::assertSame('2026-10-19T15:00:00+00:00', $first->freeUntil->at->format(\DATE_ATOM));
    }

    public function testSlotsDoNotOverlapEachOther(): void
    {
        $result = self::search(self::query('2026-10-19T07:00:00Z', '2026-10-19T10:00:00Z', 45, step: 15));

        // The last one ends exactly where the window does.
        self::assertSame(['2026-10-19T07:00:00+00:00', '2026-10-19T07:45:00+00:00', '2026-10-19T08:30:00+00:00', '2026-10-19T09:15:00+00:00'], self::starts($result));
    }

    public function testBusyTimeIsLeftOutAndNamed(): void
    {
        $busy = [self::busy('2026-10-19T08:00:00Z', '2026-10-19T09:00:00Z', 'Standup')];

        $result = self::search(self::query('2026-10-19T07:00:00Z', '2026-10-19T11:00:00Z', 60), busy: $busy);

        self::assertSame(['2026-10-19T07:00:00+00:00', '2026-10-19T09:00:00+00:00', '2026-10-19T10:00:00+00:00'], self::starts($result));
        self::assertSame(BlockKind::Busy, $result->slots[0]->freeUntil->kind);
        self::assertSame('Standup', $result->slots[0]->freeUntil->label);
        self::assertSame(BlockKind::Busy, $result->slots[1]->freeFrom->kind);
        self::assertSame('Standup', $result->slots[1]->freeFrom->label);
    }

    public function testOverlappingBusyTimeClosesUntilTheLastOneEnds(): void
    {
        $busy = [
            self::busy('2026-10-19T08:00:00Z', '2026-10-19T09:30:00Z', 'Review'),
            self::busy('2026-10-19T07:30:00Z', '2026-10-19T08:30:00Z', 'Call'),
            self::busy('2026-10-19T08:15:00Z', '2026-10-19T08:45:00Z', 'Inside'),
        ];

        $result = self::search(self::query('2026-10-19T07:00:00Z', '2026-10-19T11:00:00Z', 30), busy: $busy);

        self::assertSame('2026-10-19T07:00:00+00:00', self::starts($result)[0]);
        self::assertSame('Call', $result->slots[0]->freeUntil->label);
        self::assertSame('2026-10-19T09:30:00+00:00', self::starts($result)[1]);
        self::assertSame('Review', $result->slots[1]->freeFrom->label);
    }

    public function testStartsAreAlignedToTheStepOnTheUsersClock(): void
    {
        $busy = [self::busy('2026-10-19T07:00:00Z', '2026-10-19T08:05:00Z', 'Call')];

        $result = self::search(self::query('2026-10-19T07:00:00Z', '2026-10-19T09:00:00Z', 30, step: 15), busy: $busy);

        self::assertSame('2026-10-19T08:15:00+00:00', self::starts($result)[0]);
        self::assertSame('2026-10-19T08:05:00+00:00', $result->slots[0]->freeFrom->at->format(\DATE_ATOM));
    }

    public function testAlignmentFollowsAZoneWithAHalfHourOffset(): void
    {
        // Kolkata is at +05:30: 09:00 local is 03:30 UTC, and an hourly step starts on whole local hours.
        $rules = new SchedulingRules(new \DateTimeZone('Asia/Kolkata'), self::weekdays());

        $result = self::search(self::query('2026-10-19T03:40:00Z', '2026-10-19T08:00:00Z', 30, step: 60, limit: 2), $rules);

        self::assertSame(['2026-10-19T04:30:00+00:00', '2026-10-19T05:30:00+00:00'], self::starts($result));
    }

    public function testBufferIsKeptAroundBusyTime(): void
    {
        $rules = self::rules(buffer: 15);
        $busy = [self::busy('2026-10-19T08:00:00Z', '2026-10-19T09:00:00Z', 'Standup')];

        $result = self::search(self::query('2026-10-19T07:00:00Z', '2026-10-19T11:00:00Z', 30, step: 15), $rules, $busy);

        self::assertSame(['2026-10-19T07:00:00+00:00', '2026-10-19T09:15:00+00:00'], \array_slice(self::starts($result), 0, 2));
        self::assertSame(BlockKind::Buffer, $result->slots[0]->freeUntil->kind);
        self::assertSame('2026-10-19T07:45:00+00:00', $result->slots[0]->freeUntil->at->format(\DATE_ATOM));
        self::assertSame(BlockKind::Buffer, $result->slots[1]->freeFrom->kind);
        self::assertSame('Standup', $result->slots[1]->freeFrom->label);
    }

    public function testTravelTimeIsKeptAroundBusyTimeAtAPlace(): void
    {
        $rules = self::rules(buffer: 10, travel: 30);
        $busy = [
            self::busy('2026-10-19T08:00:00Z', '2026-10-19T09:00:00Z', 'Client visit', atPlace: true),
            self::busy('2026-10-19T11:00:00Z', '2026-10-19T12:00:00Z', 'Video call'),
        ];

        $result = self::search(self::query('2026-10-19T07:00:00Z', '2026-10-19T15:00:00Z', 30, step: 10), $rules, $busy);

        self::assertSame('2026-10-19T07:00:00+00:00', self::starts($result)[0]);
        self::assertSame('2026-10-19T07:30:00+00:00', $result->slots[0]->freeUntil->at->format(\DATE_ATOM));
        self::assertSame(BlockKind::Travel, $result->slots[0]->freeUntil->kind);
        $result = self::search(self::query('2026-10-19T09:00:00Z', '2026-10-19T15:00:00Z', 30, step: 10), $rules, $busy);

        self::assertSame('2026-10-19T09:30:00+00:00', self::starts($result)[0]);
        self::assertSame(BlockKind::Travel, $result->slots[0]->freeFrom->kind);
        self::assertSame('Client visit', $result->slots[0]->freeFrom->label);
        // The call online keeps only the buffer.
        self::assertSame(BlockKind::Buffer, $result->slots[0]->freeUntil->kind);
        self::assertSame('2026-10-19T10:50:00+00:00', $result->slots[0]->freeUntil->at->format(\DATE_ATOM));
    }

    public function testAnInPersonMeetingKeepsTravelTimeAroundEveryBusyTime(): void
    {
        $rules = self::rules(travel: 30);
        $busy = [self::busy('2026-10-19T08:00:00Z', '2026-10-19T09:00:00Z', 'Video call')];

        $online = self::search(self::query('2026-10-19T09:00:00Z', '2026-10-19T11:00:00Z', 30, limit: 1), $rules, $busy);
        $inPerson = self::search(self::query('2026-10-19T09:00:00Z', '2026-10-19T11:00:00Z', 30, limit: 1, inPerson: true), $rules, $busy);

        self::assertSame(['2026-10-19T09:00:00+00:00'], self::starts($online));
        self::assertSame(['2026-10-19T09:30:00+00:00'], self::starts($inPerson));
        self::assertSame(BlockKind::Travel, $inPerson->slots[0]->freeFrom->kind);
    }

    public function testFocusBlocksAreKeptFreeWithoutABufferAroundThem(): void
    {
        $rules = self::rules(buffer: 15);
        $busy = [new BusyTime(new \DateTimeImmutable('2026-10-19T07:00:00Z'), new \DateTimeImmutable('2026-10-19T10:00:00Z'), 'Focus: report', focus: true)];

        $result = self::search(self::query('2026-10-19T00:00:00Z', '2026-10-20T00:00:00Z', 60, limit: 1), $rules, $busy);

        self::assertSame(['2026-10-19T10:00:00+00:00'], self::starts($result));
        self::assertSame(BlockKind::Focus, $result->slots[0]->freeFrom->kind);
        self::assertSame('Focus: report', $result->slots[0]->freeFrom->label);
    }

    public function testAnEventsOwnTravelTimeIsKeptFree(): void
    {
        $rules = self::rules(travel: 30);
        $busy = [new BusyTime(new \DateTimeImmutable('2026-10-19T08:00:00Z'), new \DateTimeImmutable('2026-10-19T09:00:00Z'), 'Dentist', travelMinutes: 45)];

        $result = self::search(self::query('2026-10-19T09:00:00Z', '2026-10-19T12:00:00Z', 30, limit: 1), $rules, $busy);

        self::assertSame(['2026-10-19T09:45:00+00:00'], self::starts($result));
        self::assertSame(BlockKind::Travel, $result->slots[0]->freeFrom->kind);
    }

    public function testNothingIsProposedSoonerThanTheNotice(): void
    {
        $rules = self::rules(notice: 120);

        $result = self::search(self::query('2026-10-19T00:00:00Z', '2026-10-20T00:00:00Z', 30, limit: 1), $rules, now: '2026-10-19T08:10:00Z');

        self::assertSame(['2026-10-19T10:15:00+00:00'], self::starts($result));
        self::assertSame(BlockKind::Notice, $result->slots[0]->freeFrom->kind);
    }

    public function testAWindowThatHasPassedFindsNothing(): void
    {
        $result = self::search(self::query('2026-10-19T07:00:00Z', '2026-10-19T08:00:00Z', 30), now: '2026-10-19T09:00:00Z');

        self::assertSame([], $result->slots);
        self::assertSame(NoSlotReason::WindowPassed, $result->noSlotReason);
    }

    public function testAWeekendHasNoWorkingHours(): void
    {
        $result = self::search(self::query('2026-10-24T00:00:00Z', '2026-10-26T00:00:00Z', 30));

        self::assertSame(NoSlotReason::NoWorkingHours, $result->noSlotReason);
    }

    public function testAFullDayHasNoFreeTime(): void
    {
        $busy = [self::busy('2026-10-19T06:00:00Z', '2026-10-19T16:00:00Z', 'Offsite')];

        $result = self::search(self::query('2026-10-19T00:00:00Z', '2026-10-20T00:00:00Z', 30), busy: $busy);

        self::assertSame(NoSlotReason::NoFreeTime, $result->noSlotReason);
    }

    public function testFreeTimeShorterThanTheMeetingIsSkipped(): void
    {
        $busy = [
            self::busy('2026-10-19T07:00:00Z', '2026-10-19T08:00:00Z', 'A'),
            self::busy('2026-10-19T08:45:00Z', '2026-10-19T10:00:00Z', 'B'),
        ];

        $result = self::search(self::query('2026-10-19T07:00:00Z', '2026-10-19T12:00:00Z', 60, limit: 1), busy: $busy);

        self::assertSame(['2026-10-19T10:00:00+00:00'], self::starts($result));
    }

    public function testTheWholeWindowCanBeSearched(): void
    {
        $result = self::search(self::query('2026-10-24T08:00:00Z', '2026-10-24T10:00:00Z', 60, workingHoursOnly: false));

        self::assertSame(['2026-10-24T08:00:00+00:00', '2026-10-24T09:00:00+00:00'], self::starts($result));
        self::assertSame(BlockKind::Window, $result->slots[0]->freeFrom->kind);
    }

    public function testOverlappingWorkingHoursAreOneStretch(): void
    {
        $rules = self::rules(hours: [new WeeklyTime([1], '09:00', '13:00'), new WeeklyTime([1], '12:00', '17:00'), new WeeklyTime([1], '17:00', '18:00')]);

        $result = self::search(self::query('2026-10-19T00:00:00Z', '2026-10-20T00:00:00Z', 60, limit: 1), $rules);

        self::assertSame('2026-10-19T16:00:00+00:00', $result->slots[0]->freeUntil->at->format(\DATE_ATOM));
    }

    public function testWorkingHoursKeepTheirClockTimesAcrossDaylightSaving(): void
    {
        // Amsterdam leaves summer time on Sunday 25 October 2026: 09:00 is 07:00 UTC on Friday, 08:00 UTC on Monday.
        $result = self::search(self::query('2026-10-23T00:00:00Z', '2026-10-27T00:00:00Z', 60, perDay: 1));

        self::assertSame(['2026-10-23T07:00:00+00:00', '2026-10-26T08:00:00+00:00'], self::starts($result));
    }

    public function testADayWithoutAnHourHasOneSlotLess(): void
    {
        // Amsterdam starts summer time on Sunday 29 March 2026: 02:00 to 03:00 does not exist on the clock.
        $rules = self::rules(hours: [new WeeklyTime([7], '00:00', '24:00')]);
        $query = self::query('2026-03-28T00:00:00Z', '2026-03-31T00:00:00Z', 60, step: 60, limit: 50);

        $starts = self::starts(self::search($query, $rules));

        self::assertCount(23, $starts);
        self::assertSame('2026-03-28T23:00:00+00:00', $starts[0]);
        // 01:00 local, then 03:00 local: the slot after 01:00 to 02:00 starts when the clock shows 03:00.
        self::assertSame(['2026-03-29T00:00:00+00:00', '2026-03-29T01:00:00+00:00'], \array_slice($starts, 1, 2));
        self::assertSame('2026-03-29T21:00:00+00:00', $starts[22]);
    }

    public function testADayWithAnExtraHourHasOneSlotMore(): void
    {
        $rules = self::rules(hours: [new WeeklyTime([7], '00:00', '24:00')]);

        $starts = self::starts(self::search(self::query('2026-10-24T00:00:00Z', '2026-10-27T00:00:00Z', 60, step: 60, limit: 50), $rules));

        self::assertCount(25, $starts);
        self::assertSame('2026-10-24T22:00:00+00:00', $starts[0]);
        self::assertSame('2026-10-25T22:00:00+00:00', $starts[24]);
    }

    public function testRequiredAttendeesBusyTimeIsLeftOut(): void
    {
        $query = self::query('2026-10-19T07:00:00Z', '2026-10-19T10:00:00Z', 60, attendees: [new MeetingAttendee('sam@example.test')]);
        $attendeeBusy = ['sam@example.test' => [self::busy('2026-10-19T07:00:00Z', '2026-10-19T08:30:00Z')]];

        $result = self::search($query, attendeeBusy: $attendeeBusy);

        self::assertSame('2026-10-19T08:30:00+00:00', self::starts($result)[0]);
        self::assertSame(BlockKind::Attendee, $result->slots[0]->freeFrom->kind);
        self::assertSame('sam@example.test', $result->slots[0]->freeFrom->label);
    }

    public function testAttendeesWithUnknownAvailabilityCloseNothing(): void
    {
        $query = self::query('2026-10-19T07:00:00Z', '2026-10-19T08:00:00Z', 60, attendees: [new MeetingAttendee('sam@example.test')]);

        self::assertSame(['2026-10-19T07:00:00+00:00'], self::starts(self::search($query)));
    }

    public function testSlotsWhenOptionalAttendeesAreBusyRankLower(): void
    {
        $query = self::query('2026-10-19T07:00:00Z', '2026-10-19T10:00:00Z', 60, attendees: [new MeetingAttendee('lisa@example.test', true)]);
        $attendeeBusy = ['lisa@example.test' => [self::busy('2026-10-19T07:00:00Z', '2026-10-19T08:00:00Z')]];

        $result = self::search($query, attendeeBusy: $attendeeBusy);

        self::assertSame(['2026-10-19T08:00:00+00:00', '2026-10-19T09:00:00+00:00', '2026-10-19T07:00:00+00:00'], self::starts($result));
        self::assertSame([], $result->slots[0]->optionalConflicts);
        self::assertSame(['lisa@example.test'], $result->slots[2]->optionalConflicts);
    }

    public function testIgnoredBusyTimeIsFree(): void
    {
        $busy = [self::busy('2026-10-19T07:00:00Z', '2026-10-19T08:00:00Z', 'Being moved', references: ['event-1'])];

        $result = self::search(self::query('2026-10-19T07:00:00Z', '2026-10-19T08:00:00Z', 60, ignore: ['event-1']), busy: $busy);

        self::assertSame(['2026-10-19T07:00:00+00:00'], self::starts($result));
    }

    public function testBusyTimeWithoutLengthClosesNothing(): void
    {
        $busy = [self::busy('2026-10-19T07:30:00Z', '2026-10-19T07:30:00Z', 'Marker')];

        self::assertSame(['2026-10-19T07:00:00+00:00'], self::starts(self::search(self::query('2026-10-19T07:00:00Z', '2026-10-19T08:00:00Z', 60), busy: $busy)));
    }

    public function testAtMostThePerDayLimitOnOneDay(): void
    {
        $result = self::search(self::query('2026-10-19T00:00:00Z', '2026-10-22T00:00:00Z', 30, perDay: 2));

        self::assertSame([
            '2026-10-19T07:00:00+00:00', '2026-10-19T07:30:00+00:00',
            '2026-10-20T07:00:00+00:00', '2026-10-20T07:30:00+00:00',
            '2026-10-21T07:00:00+00:00', '2026-10-21T07:30:00+00:00',
        ], self::starts($result));
    }

    public function testTheSameInputGivesTheSameSlots(): void
    {
        $busy = [self::busy('2026-10-19T09:00:00Z', '2026-10-19T10:00:00Z', 'B'), self::busy('2026-10-19T08:00:00Z', '2026-10-19T08:30:00Z', 'A')];
        $query = self::query('2026-10-19T00:00:00Z', '2026-10-23T00:00:00Z', 45, limit: 20);

        self::assertEquals(self::search($query, busy: $busy), self::search($query, busy: array_reverse($busy)));
    }

    public function testQueryLimitsAreCheckedAndNameTheField(): void
    {
        foreach ([
            'to' => static fn () => self::query('2026-10-19T00:00:00Z', '2026-10-19T00:00:00Z', 30),
            'to ' => static fn () => self::query('2026-10-19T00:00:00Z', '2027-01-20T00:00:00Z', 30),
            'durationMinutes' => static fn () => self::query('2026-10-19T00:00:00Z', '2026-10-20T00:00:00Z', 2),
            'durationMinutes ' => static fn () => self::query('2026-10-19T00:00:00Z', '2026-10-20T00:00:00Z', 1441),
            'stepMinutes' => static fn () => self::query('2026-10-19T00:00:00Z', '2026-10-20T00:00:00Z', 30, step: 7),
            'limit' => static fn () => self::query('2026-10-19T00:00:00Z', '2026-10-20T00:00:00Z', 30, limit: 51),
            'perDay' => static fn () => self::query('2026-10-19T00:00:00Z', '2026-10-20T00:00:00Z', 30, perDay: 0),
        ] as $field => $make) {
            try {
                $make();
                self::fail('The query should have been refused.');
            } catch (InvalidMeetingTimeQuery $e) {
                self::assertSame(trim($field), $e->field);
            }
        }
    }

    public function testATodoIsPlannedInTheFirstFreeTimeBeforeItsDeadline(): void
    {
        $query = MeetingTimeQuery::beforeDeadline(new \DateTimeImmutable('2026-10-19T08:10:00Z'), new \DateTimeImmutable('2026-10-23T12:00:00Z'), 45, ['todo-1']);

        self::assertSame(1, $query->limit);
        self::assertSame(['todo-1'], $query->ignore);
        $busy = [self::busy('2026-10-19T08:00:00Z', '2026-10-19T09:00:00Z', 'Standup'), self::busy('2026-10-19T09:00:00Z', '2026-10-19T10:00:00Z', 'Old plan', references: ['todo-1'])];
        self::assertSame(['2026-10-19T09:00:00+00:00'], self::starts(self::search($query, busy: $busy, now: '2026-10-19T08:10:00Z')));
    }

    public function testATodoDeadlineFarAwayIsLookedForInTheNext92Days(): void
    {
        $query = MeetingTimeQuery::beforeDeadline(new \DateTimeImmutable('2026-10-19T08:00:00Z'), new \DateTimeImmutable('2027-06-01T00:00:00Z'), 30);

        self::assertSame('2027-01-19T08:00:00+00:00', $query->to->format(\DATE_ATOM));
    }

    public function testATodoWhoseDeadlineHasPassedCannotBePlanned(): void
    {
        $this->expectException(InvalidMeetingTimeQuery::class);
        $this->expectExceptionMessage('The deadline has passed.');

        MeetingTimeQuery::beforeDeadline(new \DateTimeImmutable('2026-10-19T08:00:00Z'), new \DateTimeImmutable('2026-10-19T08:00:00Z'), 30);
    }

    public function testBusyTimesWithTheSameTimesExplainASlotAlikeInAnyOrder(): void
    {
        $busy = [self::busy('2026-10-19T08:00:00Z', '2026-10-19T09:00:00Z', 'Review'), self::busy('2026-10-19T08:00:00Z', '2026-10-19T09:00:00Z', 'Call')];
        $query = self::query('2026-10-19T07:00:00Z', '2026-10-19T11:00:00Z', 60);

        $forward = self::search($query, busy: $busy);
        $backward = self::search($query, busy: array_reverse($busy));

        self::assertEquals($forward, $backward);
        self::assertSame('Call', $forward->slots[0]->freeUntil->label);
        self::assertSame('Call', $forward->slots[1]->freeFrom->label);
    }

    public function testAttendeeAddressesAreComparedInLowerCase(): void
    {
        $attendee = new MeetingAttendee(' Sam@Example.TEST ');
        $query = self::query('2026-10-19T07:00:00Z', '2026-10-19T09:00:00Z', 60, attendees: [$attendee]);

        $result = self::search($query, attendeeBusy: ['sam@example.test' => [self::busy('2026-10-19T07:00:00Z', '2026-10-19T08:00:00Z')]]);

        self::assertSame('sam@example.test', $attendee->address);
        self::assertSame(['2026-10-19T08:00:00+00:00'], self::starts($result));
    }

    public function testAnAttendeesWorkingHoursCloseTheTimeOutsideThem(): void
    {
        // Sam works 09:00 to 17:00 in New York (UTC-4 in October): from 13:00 UTC, which is 15:00 in Amsterdam.
        $sam = new AttendeeAvailability([], [new WeeklyTime([1, 2, 3, 4, 5], '09:00', '17:00')], new \DateTimeZone('America/New_York'));
        $query = self::query('2026-10-19T00:00:00Z', '2026-10-20T00:00:00Z', 60, attendees: [new MeetingAttendee('sam@example.test')]);

        $result = self::search($query, attendeeBusy: ['sam@example.test' => $sam->busyIn($query->from, $query->to)]);

        self::assertSame(['2026-10-19T13:00:00+00:00', '2026-10-19T14:00:00+00:00'], self::starts($result));
        self::assertSame(BlockKind::Attendee, $result->slots[0]->freeFrom->kind);
        self::assertSame('sam@example.test', $result->slots[0]->freeFrom->label);
    }

    public function testAnAttendeesWorkingHoursNeedTheirZone(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AttendeeAvailability([], [new WeeklyTime([1], '09:00', '17:00')]);
    }

    public function testWorkingHoursPastMidnightRunIntoTheNextDay(): void
    {
        self::assertEquals([new WeeklyTime([5, 6], '22:00', '24:00'), new WeeklyTime([6, 7], '00:00', '06:00')], WeeklyTime::workingHours([5, 6], '22:00', '06:00'));
        self::assertEquals([new WeeklyTime([7], '22:00', '24:00'), new WeeklyTime([1], '00:00', '06:00')], WeeklyTime::workingHours([7], '22:00', '06:00'));
        self::assertEquals([new WeeklyTime([1, 2], '09:00', '24:00')], WeeklyTime::workingHours([1, 2], '09:00', '00:00'));
        self::assertEquals([new WeeklyTime([1, 2], '09:00', '24:00'), new WeeklyTime([2, 3], '00:00', '09:00')], WeeklyTime::workingHours([1, 2], '09:00', '09:00'));
        self::assertEquals([new WeeklyTime([1, 2, 3, 4, 5, 6, 7], '00:00', '24:00')], WeeklyTime::workingHours([1, 2, 3, 4, 5, 6, 7], '00:00', '00:00'));
        self::assertSame([], WeeklyTime::workingHours([], '09:00', '17:00'));
    }

    public function testANightShiftIsOneStretchAcrossMidnight(): void
    {
        $rules = self::rules(hours: WeeklyTime::workingHours([1, 2, 3, 4, 5], '22:00', '06:00'));

        // Monday 19 October 2026: 22:00 in Amsterdam is 20:00 UTC; the shift ends at 06:00 on Tuesday, 04:00 UTC.
        $result = self::search(self::query('2026-10-19T00:00:00Z', '2026-10-21T00:00:00Z', 60, limit: 1), $rules);

        self::assertSame(['2026-10-19T20:00:00+00:00'], self::starts($result));
        self::assertSame(BlockKind::WorkingHours, $result->slots[0]->freeUntil->kind);
        self::assertSame('2026-10-20T04:00:00+00:00', $result->slots[0]->freeUntil->at->format(\DATE_ATOM));
    }

    public function testWorkingHoursAroundTheClockLeaveOnlyTheWindow(): void
    {
        $rules = self::rules(hours: WeeklyTime::workingHours([1, 2, 3, 4, 5, 6, 7], '00:00', '00:00'));

        // Saturday night: no working day, no end of the day closes anything.
        $result = self::search(self::query('2026-10-24T21:40:00Z', '2026-10-25T03:00:00Z', 60, limit: 1), $rules);

        self::assertSame(['2026-10-24T21:45:00+00:00'], self::starts($result));
        self::assertSame(BlockKind::Window, $result->slots[0]->freeFrom->kind);
        self::assertSame(BlockKind::Window, $result->slots[0]->freeUntil->kind);
    }

    public function testWorkingHoursUntilMidnightEndAtMidnight(): void
    {
        $rules = self::rules(hours: WeeklyTime::workingHours([1], '09:00', '00:00'));

        $result = self::search(self::query('2026-10-19T20:00:00Z', '2026-10-21T00:00:00Z', 60, limit: 5), $rules);

        self::assertSame(['2026-10-19T20:00:00+00:00', '2026-10-19T21:00:00+00:00'], self::starts($result));
        self::assertSame('2026-10-19T22:00:00+00:00', $result->slots[1]->freeUntil->at->format(\DATE_ATOM));
    }

    public function testWorkingHoursFromATimeTheClockSkipsStartWhenTheClockJumps(): void
    {
        // Amsterdam goes from 02:00 to 03:00 on Sunday 29 March 2026 (01:00 UTC): 02:30 does not exist that day.
        $rules = self::rules(hours: [new WeeklyTime([7], '02:30', '04:00')]);

        $result = self::search(self::query('2026-03-29T00:00:00Z', '2026-03-30T00:00:00Z', 30, step: 30, limit: 5), $rules);

        self::assertSame(['2026-03-29T01:00:00+00:00', '2026-03-29T01:30:00+00:00'], self::starts($result));
        self::assertSame(BlockKind::WorkingHours, $result->slots[0]->freeFrom->kind);
        self::assertSame('2026-03-29T01:00:00+00:00', $result->slots[0]->freeFrom->at->format(\DATE_ATOM));
    }

    public function testWeeklyTimesAreChecked(): void
    {
        foreach ([[[], '09:00', '17:00'], [[8], '09:00', '17:00'], [[1], '9:00', '17:00'], [[1], '17:00', '09:00'], [[1], '24:00', '24:00']] as [$days, $start, $end]) {
            try {
                new WeeklyTime($days, $start, $end);
                self::fail('The weekly time should have been refused.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * @param list<MeetingAttendee> $attendees
     * @param list<string>          $ignore
     */
    private static function query(string $from, string $to, int $duration, int $step = 15, int $limit = 10, ?int $perDay = null, bool $inPerson = false, bool $workingHoursOnly = true, array $attendees = [], array $ignore = []): MeetingTimeQuery
    {
        return new MeetingTimeQuery(new \DateTimeImmutable($from), new \DateTimeImmutable($to), $duration, $attendees, $step, $limit, $perDay, $inPerson, $workingHoursOnly, null, $ignore);
    }

    /**
     * @param list<WeeklyTime>|null $hours
     */
    private static function rules(?array $hours = null, int $buffer = 0, int $travel = 0, int $notice = 0): SchedulingRules
    {
        return new SchedulingRules(new \DateTimeZone(self::ZONE), $hours ?? self::weekdays(), $buffer, $travel, $notice);
    }

    /**
     * Monday to Friday, 09:00 to 17:00.
     *
     * @return list<WeeklyTime>
     */
    private static function weekdays(): array
    {
        return [new WeeklyTime([1, 2, 3, 4, 5], '09:00', '17:00')];
    }

    /**
     * @param list<string> $references
     */
    private static function busy(string $start, string $end, string $label = '', bool $atPlace = false, array $references = []): BusyTime
    {
        return new BusyTime(new \DateTimeImmutable($start), new \DateTimeImmutable($end), $label, $references, $atPlace);
    }

    /**
     * @param list<BusyTime>                $busy
     * @param array<string, list<BusyTime>> $attendeeBusy
     */
    private static function search(MeetingTimeQuery $query, ?SchedulingRules $rules = null, array $busy = [], array $attendeeBusy = [], string $now = self::NOW): SlotSearchResult
    {
        return SlotSearch::find($rules ?? self::rules(), $query, new \DateTimeImmutable($now), $busy, $attendeeBusy);
    }

    /**
     * @return list<string>
     */
    private static function starts(SlotSearchResult $result): array
    {
        return array_map(static fn (Slot $slot): string => $slot->start->format(\DATE_ATOM), $result->slots);
    }
}
