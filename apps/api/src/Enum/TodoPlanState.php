<?php

declare(strict_types=1);

namespace App\Enum;

/** How a todo's planned time in the calendar stands. */
enum TodoPlanState: string
{
    /** The time is free: nothing else is booked then, and it ends before the deadline. */
    case Planned = 'planned';
    /**
     * Something else took the time, or the deadline or the duration changed, and no other free time before the deadline
     * was found: the time stays where it was until the user plans it again or removes it.
     */
    case Conflict = 'conflict';
}
