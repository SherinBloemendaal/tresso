<?php

declare(strict_types=1);

namespace App\Batch;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * The batch sweep runs every minute (transport scheduler_batches, consumed by the messenger worker), for every user,
 * whether or not a sync worker leases their accounts.
 */
#[AsSchedule('batches')]
final readonly class BatchSweepSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return new Schedule()->add(RecurringMessage::every('1 minute', new SweepBatches()));
    }
}
