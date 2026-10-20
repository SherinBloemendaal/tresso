<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Where the meeting time finder learns when the user is busy. The calendars are one source; planned todos can be
 * another, so a todo planned into the day is not booked over.
 */
#[AutoconfigureTag(self::TAG)]
interface BusyTimeSource
{
    public const string TAG = 'app.calendar.busy_time_source';

    /**
     * Busy time that overlaps [$from, $to).
     *
     * @param \DateTimeZone     $zone        the zone all-day and floating times are read in
     * @param list<string>|null $calendarIds the calendars the search looks at; null for the visible ones
     *
     * @return iterable<BusyTime>
     */
    public function busyTimes(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $zone, ?array $calendarIds): iterable;
}
