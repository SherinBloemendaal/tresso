<?php

declare(strict_types=1);

namespace App\Enum;

/** What a reminder offers to snooze until. */
enum SnoozeChoice: string
{
    case Minutes5 = 'minutes_5';
    case Minutes10 = 'minutes_10';
    case Minutes15 = 'minutes_15';
    /** One minute before the event starts. */
    case BeforeStart = 'before_start';
    case Start = 'start';
    /** When the meeting the user is in now ends. */
    case MeetingEnd = 'meeting_end';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
