<?php

declare(strict_types=1);

namespace App\Calendar\Scheduling;

/**
 * A scheduling message (invitation, reply, cancellation) could not be sent; the message says why, for the user.
 */
final class SchedulingFailed extends \RuntimeException
{
}
