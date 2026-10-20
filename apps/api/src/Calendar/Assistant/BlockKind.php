<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

/** What closes free time: the reason a proposed slot cannot start earlier or last longer. */
enum BlockKind: string
{
    /** The edge of the window the caller searched. */
    case Window = 'window';
    /** The minimum notice: nothing is proposed sooner than that from now. */
    case Notice = 'notice';
    /** The start or end of the user's working hours. */
    case WorkingHours = 'working_hours';
    /** A focus block: busy time the user keeps for themselves, titled "Focus...", as the reminders read it. */
    case Focus = 'focus';
    /** Busy time in the user's calendars (or another busy source, such as planned todos). */
    case Busy = 'busy';
    /** The buffer the user keeps around busy time. */
    case Buffer = 'buffer';
    /** Travel time around busy time at a place, or around every busy time when the new meeting is in person. */
    case Travel = 'travel';
    /** Busy time of a required attendee. */
    case Attendee = 'attendee';
}
