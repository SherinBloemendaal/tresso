<?php

declare(strict_types=1);

namespace App\Sync\Calendar;

/**
 * A calendar list the API does not take, with the reason the worker reports to the user.
 */
final class CalendarListRejected extends \RuntimeException
{
}
