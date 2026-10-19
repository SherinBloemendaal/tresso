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

        return $property->hasTime()
            ? $property->getDateTime(new \DateTimeZone('UTC'))
            : new \DateTimeImmutable($property->getDateTime($zone)->format('Y-m-d'), $zone);
    }
}
