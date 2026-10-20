<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Every hour: clear what mail notifications say (sender, subject, start of the text) once clients no longer get them.
 */
final readonly class ScrubMailNotifications
{
}
