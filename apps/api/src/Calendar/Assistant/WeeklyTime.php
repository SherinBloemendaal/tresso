<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

/**
 * A time of day on some days of the week, in the user's zone: a range of working hours. It ends on the day it starts
 * (24:00 is the end of the day).
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
        if (!self::ordered($start, $end)) {
            throw new \InvalidArgumentException('end must be after start on the same day.');
        }
    }

    /**
     * The working hours of the notification settings (working days, start and end), which the reminders keep to as
     * well. Hours that end before they start contain no time there, so they give no working hours here either.
     *
     * @param list<int> $days
     *
     * @return list<self>
     */
    public static function workingHours(array $days, string $start, string $end): array
    {
        return [] === $days || !self::ordered($start, $end) ? [] : [new self($days, $start, $end)];
    }

    /**
     * The instants this range covers on one local day, or null when it is not one of its days. A time the clock skips
     * (a daylight saving gap) is the first moment after the gap.
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
        $end = '24:00' === $this->end
            ? new \DateTimeImmutable($day->modify('+1 day')->format('Y-m-d').' 00:00', $zone)
            : new \DateTimeImmutable($date.' '.$this->end, $zone);

        return [new \DateTimeImmutable($date.' '.$this->start, $zone), $end];
    }

    private static function ordered(string $start, string $end): bool
    {
        return $end > $start;
    }
}
