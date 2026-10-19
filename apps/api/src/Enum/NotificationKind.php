<?php

declare(strict_types=1);

namespace App\Enum;

enum NotificationKind: string
{
    /** A reminder before (or at) an event. */
    case Reminder = 'reminder';
    /** Time to leave for an event somewhere else. */
    case LeaveNow = 'leave_now';
    /** Who is coming and what was said with them, just before a meeting. */
    case Brief = 'brief';
    /** After a meeting: notes and follow-up. */
    case FollowUp = 'follow_up';
}
