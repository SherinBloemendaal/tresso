<?php

declare(strict_types=1);

namespace App\Batch;

/**
 * The times of day a user's batches go out, in their time zone, and the batch an instant falls in. Times are wall
 * clock times: a batch at 09:00 stays at 09:00 across daylight saving changes. A time that does not exist on a day
 * (skipped by a clock change) goes out at the first instant after the gap, as PHP resolves it.
 */
final readonly class BatchTimes
{
    /** Two batches a day until the user chooses their own. */
    public const array DEFAULT_TIMES = ['09:00', '16:00'];
    public const string DEFAULT_TIME_ZONE = 'UTC';
    public const int MAX_TIMES = 8;
    /** "HH:MM", 00:00 to 23:59. */
    public const string TIME_PATTERN = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';

    /** @var list<string> */
    public array $times;

    /** @param list<string> $times */
    public function __construct(array $times, public \DateTimeZone $timeZone)
    {
        $times = self::normalize($times);
        if ([] === $times) {
            throw new \InvalidArgumentException('A batch schedule needs at least one time.');
        }
        $this->times = $times;
    }

    public static function defaults(): self
    {
        return new self(self::DEFAULT_TIMES, new \DateTimeZone(self::DEFAULT_TIME_ZONE));
    }

    /**
     * @param list<string> $times
     *
     * @return list<string>
     */
    public static function normalize(array $times): array
    {
        $times = array_values(array_unique(array_filter($times, static fn (string $time): bool => 1 === preg_match(self::TIME_PATTERN, $time))));
        sort($times);

        return $times;
    }

    /**
     * An IANA zone name, the backward-compatible aliases included (browsers still report `Asia/Calcutta` or
     * `US/Eastern`); offsets and abbreviations such as `CET` are not zones with daylight saving rules.
     */
    public static function isTimeZone(string $timeZone): bool
    {
        return \in_array($timeZone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true);
    }

    /** The first batch strictly after $at. */
    public function next(\DateTimeImmutable $at): \DateTimeImmutable
    {
        $local = $at->setTimezone($this->timeZone);
        for ($day = 0; $day <= 1; ++$day) {
            foreach ($this->onDay($local->modify(\sprintf('+%d day', $day))) as $batch) {
                if ($batch > $at) {
                    return $batch;
                }
            }
        }

        // Unreachable: the next day always has a batch after $at.
        throw new \LogicException('No batch after '.$at->format(\DATE_ATOM));
    }

    /** The last batch at or before $at. */
    public function previous(\DateTimeImmutable $at): \DateTimeImmutable
    {
        $local = $at->setTimezone($this->timeZone);
        for ($day = 0; $day >= -1; --$day) {
            foreach (array_reverse($this->onDay($local->modify(\sprintf('%d day', $day)))) as $batch) {
                if ($batch <= $at) {
                    return $batch;
                }
            }
        }

        throw new \LogicException('No batch before '.$at->format(\DATE_ATOM));
    }

    /**
     * The batches of the local day of $day, as instants in UTC.
     *
     * @return list<\DateTimeImmutable>
     */
    private function onDay(\DateTimeImmutable $day): array
    {
        $batches = [];
        foreach ($this->times as $time) {
            [$hour, $minute] = array_map(intval(...), explode(':', $time));
            $batches[] = $day->setTime($hour, $minute)->setTimezone(new \DateTimeZone('UTC'));
        }

        return $batches;
    }
}
