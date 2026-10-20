<?php

declare(strict_types=1);

namespace App\Notification;

/** Deliver a mail notification; sent after the classification commits, or delayed to the end of a hold. */
final readonly class SendMailNotification
{
    public function __construct(public string $notificationId)
    {
    }
}
