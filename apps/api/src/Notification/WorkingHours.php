<?php

declare(strict_types=1);

namespace App\Notification;

use App\Calendar\Assistant\WeeklyTime;
use App\Entity\NotificationPreferences;

/**
 * The user's working hours: on their working days, from the start to the end time, in their zone. Hours that end at
 * or before they start run past midnight into the next day, as the meeting time finder reads them
 * ({@see WeeklyTime::workingHours()}).
 */
final class WorkingHours
{
    public static function contains(NotificationPreferences $preferences, \DateTimeImmutable $moment): bool
    {
        foreach (self::between($preferences, $moment->modify('-1 day'), $moment->modify('+1 day')) as [$start, $end]) {
            if ($start <= $moment && $moment < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * The next start of working hours at or after the moment, or null without working hours.
     */
    public static function nextStart(NotificationPreferences $preferences, \DateTimeImmutable $moment): ?\DateTimeImmutable
    {
        foreach (self::between($preferences, $moment, $moment->modify('+8 days')) as [$start]) {
            if ($start >= $moment) {
                return $start->setTimezone(self::zone($preferences));
            }
        }

        return null;
    }

    /**
     * A reminder that would come before the working day, for an event that starts before the working day too, comes
     * at the end of the working day before instead (a reminder for a 08:00 meeting at 17:30 the evening before, not
     * at 07:50). The working day is the stretch of working hours that starts later on the reminder's own day. Null when
     * it stays where it is.
     */
    public static function shiftEarly(NotificationPreferences $preferences, \DateTimeImmutable $fireAt, \DateTimeImmutable $startsAt): ?\DateTimeImmutable
    {
        $zone = self::zone($preferences);
        $previousEnd = null;
        foreach (self::between($preferences, $fireAt->modify('-8 days'), $fireAt->modify('+1 day')) as [$start, $end]) {
            if ($start <= $fireAt && $fireAt < $end) {
                return null;
            }
            if ($end <= $fireAt) {
                $previousEnd = $end;
                continue;
            }
            $sameDay = $start->setTimezone($zone)->format('Y-m-d') === $fireAt->setTimezone($zone)->format('Y-m-d');

            return $sameDay && $startsAt <= $start ? $previousEnd?->setTimezone($zone) : null;
        }

        return null;
    }

    /**
     * @return list<array{\DateTimeImmutable, \DateTimeImmutable}>
     */
    private static function between(NotificationPreferences $preferences, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $hours = WeeklyTime::workingHours($preferences->getWorkDays(), $preferences->getWorkStart(), $preferences->getWorkEnd());

        return WeeklyTime::intervals($hours, $from, $to, self::zone($preferences));
    }

    private static function zone(NotificationPreferences $preferences): \DateTimeZone
    {
        return new \DateTimeZone($preferences->getTimeZone());
    }
}
