<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\NotificationPreferences;

/**
 * Whether a moment falls in the user's quiet hours, and when those end. Quiet hours that pass midnight belong to the
 * day they start on.
 */
final class QuietHours
{
    /**
     * The end of the quiet hours the moment falls in, or null when it is not quiet then.
     */
    public static function endOf(NotificationPreferences $preferences, \DateTimeImmutable $moment): ?\DateTimeImmutable
    {
        if (!$preferences->isQuietHoursEnabled()) {
            return null;
        }
        $zone = new \DateTimeZone($preferences->getTimeZone());
        $local = $moment->setTimezone($zone);

        // A window may have started today or (past midnight) yesterday.
        foreach ([$local->modify('-1 day'), $local] as $day) {
            if (!\in_array((int) $day->format('N'), $preferences->getQuietDays(), true)) {
                continue;
            }
            $start = self::at($day, $preferences->getQuietStart());
            $end = self::at($day, $preferences->getQuietEnd());
            if ($end <= $start) {
                $end = $end->modify('+1 day');
            }
            if ($local >= $start && $local < $end) {
                return $end;
            }
        }

        return null;
    }

    private static function at(\DateTimeImmutable $day, string $time): \DateTimeImmutable
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $time));

        return $day->setTime($hour, $minute);
    }
}
