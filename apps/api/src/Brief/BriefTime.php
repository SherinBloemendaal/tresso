<?php

declare(strict_types=1);

namespace App\Brief;

/**
 * The moments a daily brief is due, read in the user's time zone.
 */
final class BriefTime
{
    /**
     * The first delivery time strictly after $now, in UTC. On a day the clock skips the time (the start of summer
     * time) the brief comes at the same distance past midnight, the way PHP moves a time in a gap: 02:30 becomes 03:30.
     *
     * @param string $deliverAt HH:MM
     * @param string $timeZone  an IANA zone
     */
    public static function nextRun(string $deliverAt, string $timeZone, \DateTimeImmutable $now): \DateTimeImmutable
    {
        if (1 !== preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $deliverAt, $match)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a time of day (HH:MM).', $deliverAt));
        }
        $zone = new \DateTimeZone($timeZone);
        $local = $now->setTimezone($zone);
        $candidate = $local->setTime((int) $match[1], (int) $match[2]);
        if ($candidate <= $now) {
            $candidate = $local->modify('+1 day')->setTime((int) $match[1], (int) $match[2]);
        }

        return $candidate->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function isZone(string $timeZone): bool
    {
        return \in_array($timeZone, \DateTimeZone::listIdentifiers(), true) || 'UTC' === $timeZone;
    }
}
