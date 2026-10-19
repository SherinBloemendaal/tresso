<?php

declare(strict_types=1);

namespace App\Notification;

use App\Calendar\ICalendar\Props as CalendarProps;
use Sabre\VObject\Component\VEvent;

/**
 * Moments of an expanded occurrence: dates are read as midnight in the user's zone.
 */
final class Props
{
    public static function instant(VEvent $instance, string $name, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        $property = CalendarProps::dateTime($instance, $name);
        if (null === $property) {
            return null;
        }

        // In UTC: date-time columns keep the wall clock only, so an instant in another zone would be stored wrong.
        $utc = new \DateTimeZone('UTC');

        return $property->hasTime()
            ? $property->getDateTime($utc)->setTimezone($utc)
            : new \DateTimeImmutable($property->getDateTime($zone)->format('Y-m-d'), $zone)->setTimezone($utc);
    }
}
