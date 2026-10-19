<?php

declare(strict_types=1);

namespace App\Notification;

/** Plan all of a user's notifications again (rules, preferences or location changed). */
final readonly class PlanUserNotifications
{
    public function __construct(public string $userId)
    {
    }
}
