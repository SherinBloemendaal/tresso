<?php

declare(strict_types=1);

namespace App\Brief;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * The brief sweep runs every minute (transport scheduler_daily_brief, consumed by the messenger worker), so a brief
 * comes within a minute of the time the user picked.
 */
#[AsSchedule('daily_brief')]
final readonly class DailyBriefSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return new Schedule()
            ->add(RecurringMessage::every('1 minute', new SweepDailyBriefs()))
            ->add(RecurringMessage::every('1 hour', new CreateMissingBriefSettings()));
    }
}
