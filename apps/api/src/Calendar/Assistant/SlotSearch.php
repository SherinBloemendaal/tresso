<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

/**
 * Finds free slots, deterministically: the same rules, query, busy time and moment always give the same slots in the
 * same order, and every slot says what closes its free time on each side.
 *
 * 1. The window starts at the later of `from` and now plus the minimum notice, and ends at `to`.
 * 2. Open time is the working hours in the window (the whole window when the query does not keep to them), read on
 *    each local day in the user's zone, so a day with a daylight saving change has its working hours at the same clock
 *    times and is an hour shorter or longer.
 * 3. Closed time is taken out: the user's busy time with its buffer before and after (or travel time, when the busy
 *    time is at a place or names its own travel time, or the new meeting is in person, and travel takes longer than
 *    the buffer), focus blocks as they are, and the busy time of required attendees whose availability is known. Busy
 *    time that takes no time closes nothing.
 * 4. Slots start on multiples of the step past the hour on the user's clock and fit in the free time that is left.
 * 5. Slots are ranked by how many optional attendees are busy then, then by start; slots overlapping a better one, and
 *    slots past the limit per day, are left out.
 */
final class SlotSearch
{
    /**
     * @param list<BusyTime>                $busy         the user's busy time
     * @param array<string, list<BusyTime>> $attendeeBusy busy time by attendee address; an attendee missing here has
     *                                                    unknown availability and closes nothing
     */
    public static function find(SchedulingRules $rules, MeetingTimeQuery $query, \DateTimeImmutable $now, array $busy, array $attendeeBusy = []): SlotSearchResult
    {
        $zone = $rules->zone;
        $notice = self::utc($now)->modify(\sprintf('+%d minutes', $rules->minimumNoticeMinutes));
        $from = self::utc($query->from);
        $start = $notice > $from ? new Boundary($notice, BlockKind::Notice) : new Boundary($from, BlockKind::Window);
        $end = new Boundary(self::utc($query->to), BlockKind::Window);
        if ($start->at >= $end->at) {
            return new SlotSearchResult([], NoSlotReason::WindowPassed);
        }

        $open = $query->workingHoursOnly
            ? self::clip(self::weekly($rules->workingHours, $start->at, $end->at, $zone), $start, $end)
            : [[$start, $end]];
        if ([] === $open) {
            return new SlotSearchResult([], NoSlotReason::NoWorkingHours);
        }

        $free = self::subtract($open, self::closed($rules, $query, $start->at, $end->at, $busy, $attendeeBusy));
        $slots = self::pick(self::candidates($free, $query, $zone, $attendeeBusy), $query, $zone);

        return new SlotSearchResult($slots, [] === $slots ? NoSlotReason::NoFreeTime : null);
    }

    /**
     * @param list<BusyTime>                $busy
     * @param array<string, list<BusyTime>> $attendeeBusy
     *
     * @return list<array{Boundary, Boundary}>
     */
    private static function closed(SchedulingRules $rules, MeetingTimeQuery $query, \DateTimeImmutable $from, \DateTimeImmutable $to, array $busy, array $attendeeBusy): array
    {
        $blocks = [];
        foreach ($busy as $time) {
            if (!self::counts($time, $query)) {
                continue;
            }
            $start = self::utc($time->start);
            $end = self::utc($time->end);
            $kind = $time->focus ? BlockKind::Focus : BlockKind::Busy;
            $blocks[] = [new Boundary($start, $kind, $time->label), new Boundary($end, $kind, $time->label)];
            if ($time->focus) {
                continue;
            }

            $travel = $time->atPlace || null !== $time->travelMinutes || $query->inPerson ? $time->travelMinutes ?? $rules->travelMinutes : 0;
            $padding = max($rules->bufferMinutes, $travel);
            if (0 === $padding) {
                continue;
            }
            $kind = $travel > $rules->bufferMinutes ? BlockKind::Travel : BlockKind::Buffer;
            $before = $start->modify(\sprintf('-%d minutes', $padding));
            $after = $end->modify(\sprintf('+%d minutes', $padding));
            $blocks[] = [new Boundary($before, $kind, $time->label), new Boundary($start, $kind, $time->label)];
            $blocks[] = [new Boundary($end, $kind, $time->label), new Boundary($after, $kind, $time->label)];
        }
        foreach ($query->attendees as $attendee) {
            if ($attendee->optional) {
                continue;
            }
            foreach ($attendeeBusy[$attendee->address] ?? [] as $time) {
                if (self::counts($time, $query)) {
                    $blocks[] = [new Boundary(self::utc($time->start), BlockKind::Attendee, $attendee->address), new Boundary(self::utc($time->end), BlockKind::Attendee, $attendee->address)];
                }
            }
        }
        usort($blocks, static fn (array $a, array $b): int => [$a[0]->at, $a[1]->at] <=> [$b[0]->at, $b[1]->at]);

        return $blocks;
    }

    private static function counts(BusyTime $time, MeetingTimeQuery $query): bool
    {
        return $time->end > $time->start && [] === array_intersect($time->references, $query->ignore);
    }

    /**
     * The open time less the closed time, each free stretch with what closes it on either side.
     *
     * @param list<array{Boundary, Boundary}> $open   in order, not overlapping
     * @param list<array{Boundary, Boundary}> $closed in order of start
     *
     * @return list<array{Boundary, Boundary}>
     */
    private static function subtract(array $open, array $closed): array
    {
        $free = [];
        foreach ($open as [$start, $end]) {
            $cursor = $start;
            foreach ($closed as [$blockStart, $blockEnd]) {
                if ($blockStart->at >= $end->at) {
                    break;
                }
                if ($blockEnd->at <= $cursor->at) {
                    continue;
                }
                if ($blockStart->at > $cursor->at) {
                    $free[] = [$cursor, $blockStart];
                }
                $cursor = $blockEnd;
                if ($cursor->at >= $end->at) {
                    break;
                }
            }
            if ($cursor->at < $end->at) {
                $free[] = [$cursor, $end];
            }
        }

        return $free;
    }

    /**
     * Every aligned start in the free time where the meeting fits, in order.
     *
     * @param list<array{Boundary, Boundary}> $free
     * @param array<string, list<BusyTime>>   $attendeeBusy
     *
     * @return list<Slot>
     */
    private static function candidates(array $free, MeetingTimeQuery $query, \DateTimeZone $zone, array $attendeeBusy): array
    {
        $candidates = [];
        $duration = \sprintf('+%d minutes', $query->durationMinutes);
        $step = \sprintf('+%d minutes', $query->stepMinutes);
        foreach ($free as [$from, $until]) {
            for ($start = self::align($from->at, $query->stepMinutes, $zone); ($end = $start->modify($duration)) <= $until->at; $start = self::align($start->modify($step), $query->stepMinutes, $zone)) {
                $candidates[] = new Slot($start, $end, $from, $until, self::optionalConflicts($start, $end, $query, $attendeeBusy));
            }
        }

        return $candidates;
    }

    /**
     * @param array<string, list<BusyTime>> $attendeeBusy
     *
     * @return list<string>
     */
    private static function optionalConflicts(\DateTimeImmutable $start, \DateTimeImmutable $end, MeetingTimeQuery $query, array $attendeeBusy): array
    {
        $conflicts = [];
        foreach ($query->attendees as $attendee) {
            if (!$attendee->optional) {
                continue;
            }
            foreach ($attendeeBusy[$attendee->address] ?? [] as $time) {
                if (self::counts($time, $query) && $time->overlaps($start, $end)) {
                    $conflicts[] = $attendee->address;
                    break;
                }
            }
        }

        return $conflicts;
    }

    /**
     * @param list<Slot> $candidates in order of start
     *
     * @return list<Slot>
     */
    private static function pick(array $candidates, MeetingTimeQuery $query, \DateTimeZone $zone): array
    {
        // usort is stable: candidates with as many conflicts keep their order of start.
        usort($candidates, static fn (Slot $a, Slot $b): int => \count($a->optionalConflicts) <=> \count($b->optionalConflicts));

        $picked = [];
        $perDay = [];
        foreach ($candidates as $candidate) {
            $day = $candidate->start->setTimezone($zone)->format('Y-m-d');
            if (null !== $query->perDay && ($perDay[$day] ?? 0) >= $query->perDay) {
                continue;
            }
            foreach ($picked as $slot) {
                if ($candidate->start < $slot->end && $slot->start < $candidate->end) {
                    continue 2;
                }
            }
            $picked[] = $candidate;
            $perDay[$day] = ($perDay[$day] ?? 0) + 1;
            if (\count($picked) >= $query->limit) {
                break;
            }
        }

        return $picked;
    }

    /**
     * The working hours on every local day that touches [$from, $to), in order, overlapping ranges merged.
     *
     * @param list<WeeklyTime> $times
     *
     * @return list<array{Boundary, Boundary}>
     */
    private static function weekly(array $times, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $zone): array
    {
        $ranges = [];
        $last = $to->setTimezone($zone)->modify('+1 day')->format('Y-m-d');
        for ($date = $from->setTimezone($zone)->modify('-1 day')->format('Y-m-d'); $date <= $last; $date = new \DateTimeImmutable($date.' 12:00', $zone)->modify('+1 day')->format('Y-m-d')) {
            foreach ($times as $time) {
                $range = $time->on($date, $zone);
                if (null === $range || $range[1] <= $from || $range[0] >= $to) {
                    continue;
                }
                $ranges[] = [new Boundary(self::utc($range[0]), BlockKind::WorkingHours), new Boundary(self::utc($range[1]), BlockKind::WorkingHours)];
            }
        }
        usort($ranges, static fn (array $a, array $b): int => [$a[0]->at, $a[1]->at] <=> [$b[0]->at, $b[1]->at]);

        $merged = [];
        foreach ($ranges as $range) {
            $previous = array_key_last($merged);
            if (null !== $previous && $range[0]->at <= $merged[$previous][1]->at) {
                if ($range[1]->at > $merged[$previous][1]->at) {
                    $merged[$previous][1] = $range[1];
                }
                continue;
            }
            $merged[] = $range;
        }

        return $merged;
    }

    /**
     * @param list<array{Boundary, Boundary}> $ranges
     *
     * @return list<array{Boundary, Boundary}>
     */
    private static function clip(array $ranges, Boundary $start, Boundary $end): array
    {
        $clipped = [];
        foreach ($ranges as [$from, $until]) {
            if ($until->at <= $start->at || $from->at >= $end->at) {
                continue;
            }
            $clipped[] = [$from->at < $start->at ? $start : $from, $until->at > $end->at ? $end : $until];
        }

        return $clipped;
    }

    /**
     * The first start at or after $time on a multiple of $step minutes past the hour on the user's clock. Stepping over
     * a daylight saving change can land between multiples, so the clock is read again after each move.
     */
    private static function align(\DateTimeImmutable $time, int $step, \DateTimeZone $zone): \DateTimeImmutable
    {
        for ($tries = 0; $tries < 3; ++$tries) {
            $local = $time->setTimezone($zone);
            $rest = ((int) $local->format('G') * 3600 + (int) $local->format('i') * 60 + (int) $local->format('s')) % ($step * 60);
            if (0 === $rest) {
                return $time;
            }
            $time = $time->modify(\sprintf('+%d seconds', $step * 60 - $rest));
        }

        return $time;
    }

    /** As a UTC instant on a whole second (rounded up), so steps are elapsed time and starts compare exactly. */
    private static function utc(\DateTimeImmutable $time): \DateTimeImmutable
    {
        $seconds = $time->getTimestamp() + ('000000' === $time->format('u') ? 0 : 1);

        return new \DateTimeImmutable('@'.$seconds);
    }
}
