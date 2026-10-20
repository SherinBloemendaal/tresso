<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

/**
 * A time of day on some days of the week, in the user's zone: a range of working hours. It ends on the day it starts
 * (24:00 is the end of the day); hours past midnight are two ranges ({@see self::workingHours()}).
 */
final readonly class WeeklyTime
{
    private const string START_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';
    private const string END_PATTERN = '/^(([01]\d|2[0-3]):[0-5]\d|24:00)$/';

    /**
     * @param list<int> $days ISO-8601 weekdays, 1 is Monday and 7 is Sunday
     *
     * @throws \InvalidArgumentException when a day or time is not valid, or the range ends before it starts
     */
    public function __construct(
        public array $days,
        public string $start,
        public string $end,
    ) {
        if ([] === $days || [] !== array_diff($days, range(1, 7))) {
            throw new \InvalidArgumentException('days must be weekdays from 1 (Monday) to 7 (Sunday).');
        }
        if (1 !== preg_match(self::START_PATTERN, $start) || 1 !== preg_match(self::END_PATTERN, $end)) {
            throw new \InvalidArgumentException('start and end must be times such as 09:00 and 17:30.');
        }
        if ($end <= $start) {
            throw new \InvalidArgumentException('end must be after start on the same day.');
        }
    }

    /**
     * The working hours of the notification settings: from the start to the end time on each working day. Hours that
     * end at or before they start run past midnight: 22:00 to 06:00 on Monday is Monday 22:00 to 24:00 and Tuesday
     * 00:00 to 06:00, and 09:00 to 00:00 ends at midnight. Hours that end when they start last a whole day: 00:00 to
     * 00:00 is the whole working day (every day of the week is around the clock), 09:00 to 09:00 ends at 09:00 the
     * next day.
     *
     * @param list<int> $days
     *
     * @return list<self>
     */
    public static function workingHours(array $days, string $start, string $end): array
    {
        if ([] === $days) {
            return [];
        }
        if ($end > $start) {
            return [new self($days, $start, $end)];
        }
        $evenings = [new self($days, $start, '24:00')];
        if ('00:00' === $end) {
            return $evenings;
        }
        $mornings = array_map(static fn (int $day): int => $day % 7 + 1, $days);
        sort($mornings);

        return [...$evenings, new self($mornings, '00:00', $end)];
    }

    /**
     * The ranges of the weekly times on every local day that touches [$from, $to), in order, overlapping and touching
     * ranges merged (so night hours are one stretch across midnight), as UTC instants.
     *
     * @param list<self> $times
     *
     * @return list<array{\DateTimeImmutable, \DateTimeImmutable}>
     */
    public static function intervals(array $times, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $zone): array
    {
        $utc = new \DateTimeZone('UTC');
        $ranges = [];
        $last = $to->setTimezone($zone)->modify('+1 day')->format('Y-m-d');
        for ($date = $from->setTimezone($zone)->modify('-1 day')->format('Y-m-d'); $date <= $last; $date = new \DateTimeImmutable($date.' 12:00', $zone)->modify('+1 day')->format('Y-m-d')) {
            foreach ($times as $time) {
                $range = $time->on($date, $zone);
                if (null !== $range && $range[1] > $from && $range[0] < $to) {
                    $ranges[] = [$range[0]->setTimezone($utc), $range[1]->setTimezone($utc)];
                }
            }
        }
        usort($ranges, static fn (array $a, array $b): int => $a <=> $b);

        $merged = [];
        foreach ($ranges as $range) {
            $previous = array_key_last($merged);
            if (null !== $previous && $range[0] <= $merged[$previous][1]) {
                $merged[$previous][1] = max($merged[$previous][1], $range[1]);
                continue;
            }
            $merged[] = $range;
        }

        return $merged;
    }

    /**
     * The instants this range covers on one local day, or null when it is not one of its days. A time the clock skips
     * (in the gap of a change to summer time) is the moment the clock jumps: working hours from 02:30 on that day start
     * when the clock shows 03:00, not at 03:30 as PHP would read 02:30.
     *
     * @param string $date Y-m-d in $zone
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}|null
     */
    public function on(string $date, \DateTimeZone $zone): ?array
    {
        $day = new \DateTimeImmutable($date.' 12:00', $zone);
        if (!\in_array((int) $day->format('N'), $this->days, true)) {
            return null;
        }
        $end = '24:00' === $this->end ? self::wallClock($day->modify('+1 day')->format('Y-m-d'), '00:00', $zone) : self::wallClock($date, $this->end, $zone);

        return [self::wallClock($date, $this->start, $zone), $end];
    }

    private static function wallClock(string $date, string $time, \DateTimeZone $zone): \DateTimeImmutable
    {
        $moment = new \DateTimeImmutable($date.' '.$time, $zone);
        if ($moment->format('Y-m-d H:i') === $date.' '.$time) {
            return $moment;
        }
        // PHP moved the time forward by the length of the gap; the gap ends at the last change before that moment.
        $changes = $zone->getTransitions($moment->getTimestamp() - 86400, $moment->getTimestamp());
        $change = end($changes);
        if (false === $change) {
            return $moment;
        }

        return new \DateTimeImmutable('@'.$change['ts'])->setTimezone($zone);
    }
}
