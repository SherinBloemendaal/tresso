<?php

declare(strict_types=1);

namespace App\Notification;

/** Deliver a planned notification; sent with a delay up to its fire time. */
final readonly class FireNotification
{
    public function __construct(public string $notificationId)
    {
    }
}
