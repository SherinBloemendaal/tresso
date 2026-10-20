<?php

declare(strict_types=1);

namespace App\Notification;

/** Every minute: deliver mail notifications whose message got lost, and send failed deliveries again. */
final readonly class SweepMailNotifications
{
}
