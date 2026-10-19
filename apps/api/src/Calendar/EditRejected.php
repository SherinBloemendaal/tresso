<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * A change to an event that cannot be made as asked; the message says why, for the user.
 */
final class EditRejected extends \RuntimeException
{
}
