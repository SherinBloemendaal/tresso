<?php

declare(strict_types=1);

namespace App\Brief;

/**
 * The moments a daily brief is due, read in the user's time zone.
 */
final class BriefTime
{
    /**
     * The first delivery time strictly after $now, in UTC, on a later day than the one the last brief was due on: one
     * brief a day, also when the time or the zone changed after today's brief, or when the clock goes back and repeats
     * the hour. The day a brief was due counts, not the day it was made: a 23:59 brief made just after midnight still
     * leaves that new day its own. On a day the clock skips the time (the start of summer time) the brief comes at the
     * same distance past midnight, the way PHP moves a time in a gap: 02:30 becomes 03:30.
     *
     * @param string      $deliverAt  HH:MM
     * @param string      $timeZone   an IANA zone
     * @param string|null $lastDueDay Y-m-d, the local day the last brief was due on, if one was made
     */
    public static function nextRun(string $deliverAt, string $timeZone, \DateTimeImmutable $now, ?string $lastDueDay = null): \DateTimeImmutable
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
        if (null !== $lastDueDay && $candidate->format('Y-m-d') <= $lastDueDay) {
            $candidate = new \DateTimeImmutable($lastDueDay, $zone)->modify('+1 day')->setTime($hour, $minute);
        }

        return $candidate->setTimezone(new \DateTimeZone('UTC'));
    }

    /** The local day (Y-m-d) a moment falls on in the zone. */
    public static function localDay(\DateTimeImmutable $moment, string $timeZone): string
    {
        return $moment->setTimezone(new \DateTimeZone($timeZone))->format('Y-m-d');
    }
}
