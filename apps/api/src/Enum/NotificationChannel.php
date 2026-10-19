<?php

declare(strict_types=1);

namespace App\Enum;

/** How a notification reaches the user. */
enum NotificationChannel: string
{
    /** Every device of the user: web push, mobile push and the desktop app. */
    case Push = 'push';
    case Email = 'email';
}
