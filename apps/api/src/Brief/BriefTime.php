<?php

declare(strict_types=1);

namespace App\Brief;

/**
 * The moments a daily brief is due, read in the user's time zone.
 */
final class BriefTime
{
    /**
     * The first delivery time strictly after $now, in UTC, on a later day than the last brief: one brief a day, also
     * when the time or the zone changed after today's brief, or when the clock goes back and repeats the hour. On a
     * day the clock skips the time (the start of summer time) the brief comes at the same distance past midnight, the
     * way PHP moves a time in a gap: 02:30 becomes 03:30.
     *
     * @param string                  $deliverAt HH:MM
     * @param string                  $timeZone  an IANA zone
     * @param \DateTimeImmutable|null $lastRun   when the last brief was made, if one was
     */
    public static function nextRun(string $deliverAt, string $timeZone, \DateTimeImmutable $now, ?\DateTimeImmutable $lastRun = null): \DateTimeImmutable
    {
        if (1 !== preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $deliverAt, $match)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a time of day (HH:MM).', $deliverAt));
        }
        [$hour, $minute] = [(int) $match[1], (int) $match[2]];
        $zone = new \DateTimeZone($timeZone);
        $local = $now->setTimezone($zone);
        $candidate = $local->setTime($hour, $minute);
        if ($candidate <= $now) {
            $candidate = $local->modify('+1 day')->setTime($hour, $minute);
        }
        if (null !== $lastRun && $candidate->format('Y-m-d') <= $lastRun->setTimezone($zone)->format('Y-m-d')) {
            $candidate = $lastRun->setTimezone($zone)->modify('+1 day')->setTime($hour, $minute);
        }

        return $candidate->setTimezone(new \DateTimeZone('UTC'));
    }

    /** Whether a brief was made today, in the zone: then the next one waits for tomorrow. */
    public static function ranToday(?\DateTimeImmutable $lastRun, string $timeZone, \DateTimeImmutable $now): bool
    {
        if (null === $lastRun) {
            return false;
        }
        $zone = new \DateTimeZone($timeZone);

        return $lastRun->setTimezone($zone)->format('Y-m-d') === $now->setTimezone($zone)->format('Y-m-d');
    }
}
