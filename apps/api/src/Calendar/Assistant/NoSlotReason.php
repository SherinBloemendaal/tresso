<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

/** Why a search found nothing. */
enum NoSlotReason: string
{
    /** The window lies before the minimum notice from now. */
    case WindowPassed = 'window_passed';
    /** The window holds no working hours. */
    case NoWorkingHours = 'no_working_hours';
    /** There is no free time long enough. */
    case NoFreeTime = 'no_free_time';
}
