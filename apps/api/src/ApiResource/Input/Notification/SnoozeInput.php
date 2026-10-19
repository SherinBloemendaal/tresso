<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Notification;

use App\Enum\SnoozeChoice;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Snooze for a number of minutes, or until one of the moments a reminder offers (SnoozeChoice): one minute before
 * the event, its start, or the end of the meeting the user is in. "until" wins when both are given.
 */
final class SnoozeInput
{
    #[Assert\Range(min: 1, max: 1440)]
    public int $minutes = 5;

    #[Assert\Choice(callback: [SnoozeChoice::class, 'values'])]
    public ?string $until = null;
}
