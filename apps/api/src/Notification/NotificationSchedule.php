<?php

declare(strict_types=1);

namespace App\Notification;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * The notification sweeps, calendar and mail, run every minute (transport scheduler_notifications, consumed by the
 * messenger worker).
 */
#[AsSchedule('notifications')]
final readonly class NotificationSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return new Schedule()->add(
            RecurringMessage::every('1 minute', new SweepNotifications()),
            RecurringMessage::every('1 minute', new SweepMailNotifications()),
        );
    }
}
