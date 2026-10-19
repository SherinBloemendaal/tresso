<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\NotificationPreferences;

/**
 * The user's working hours: on their working days, from the start to the end time, in their zone.
 */
final class WorkingHours
{
    public static function contains(NotificationPreferences $preferences, \DateTimeImmutable $moment): bool
    {
        $local = $moment->setTimezone(new \DateTimeZone($preferences->getTimeZone()));
        if (!self::isWorkDay($preferences, $local)) {
            return false;
        }

        return $local >= self::at($local, $preferences->getWorkStart()) && $local < self::at($local, $preferences->getWorkEnd());
    }

    /**
     * The next start of working hours at or after the moment, or null without working days.
     */
    public static function nextStart(NotificationPreferences $preferences, \DateTimeImmutable $moment): ?\DateTimeImmutable
    {
        $local = $moment->setTimezone(new \DateTimeZone($preferences->getTimeZone()));
        for ($day = 0; $day <= 7; ++$day) {
            $candidate = $local->modify(\sprintf('+%d days', $day));
            $start = self::at($candidate, $preferences->getWorkStart());
            if (self::isWorkDay($preferences, $candidate) && $start >= $local) {
                return $start;
            }
        }

        return null;
    }

    /**
     * A reminder that would come before the working day, for an event that starts before the working day too, comes
     * at the end of the working day before instead (a reminder for a 08:00 meeting at 17:30 the evening before, not
     * at 07:50). Null when it stays where it is.
     */
    public static function shiftEarly(NotificationPreferences $preferences, \DateTimeImmutable $fireAt, \DateTimeImmutable $startsAt): ?\DateTimeImmutable
    {
        $zone = new \DateTimeZone($preferences->getTimeZone());
        $fire = $fireAt->setTimezone($zone);
        $workStart = self::at($fire, $preferences->getWorkStart());
        if (!self::isWorkDay($preferences, $fire) || $fire >= $workStart || $startsAt->setTimezone($zone) > $workStart) {
            return null;
        }
        for ($day = 1; $day <= 7; ++$day) {
            $before = $fire->modify(\sprintf('-%d days', $day));
            if (self::isWorkDay($preferences, $before)) {
                return self::at($before, $preferences->getWorkEnd());
            }
        }

        return null;
    }

    private static function isWorkDay(NotificationPreferences $preferences, \DateTimeImmutable $local): bool
    {
        return \in_array((int) $local->format('N'), $preferences->getWorkDays(), true);
    }

    private static function at(\DateTimeImmutable $day, string $time): \DateTimeImmutable
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $time));

        return $day->setTime($hour, $minute);
    }
}
