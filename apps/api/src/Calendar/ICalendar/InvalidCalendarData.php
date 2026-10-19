<?php

declare(strict_types=1);

namespace App\Calendar\ICalendar;

/**
 * iCalendar data that cannot be read as events (no UID, no start, not iCalendar at all).
 */
final class InvalidCalendarData extends \RuntimeException
{
}
