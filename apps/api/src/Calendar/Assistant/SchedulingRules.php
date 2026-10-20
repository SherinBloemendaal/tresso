<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

/**
 * The user's rules for when they can be booked: working hours in their zone, a buffer around busy time, travel time
 * around busy time at a place, and how much notice they need.
 */
final readonly class SchedulingRules
{
    public const int MAX_BUFFER_MINUTES = 120;
    public const int MAX_TRAVEL_MINUTES = 240;
    public const int MAX_NOTICE_MINUTES = 20160;

    /**
     * @param list<WeeklyTime> $workingHours
     */
    public function __construct(
        public \DateTimeZone $zone,
        public array $workingHours,
        public int $bufferMinutes = 0,
        /** Travel time to and from busy time at a place that does not name its own. */
        public int $travelMinutes = 0,
        public int $minimumNoticeMinutes = 0,
    ) {
        if ($bufferMinutes < 0 || $bufferMinutes > self::MAX_BUFFER_MINUTES || $travelMinutes < 0 || $travelMinutes > self::MAX_TRAVEL_MINUTES || $minimumNoticeMinutes < 0 || $minimumNoticeMinutes > self::MAX_NOTICE_MINUTES) {
            throw new \InvalidArgumentException('Buffer, travel time and notice must be within their limits.');
        }
    }
}
