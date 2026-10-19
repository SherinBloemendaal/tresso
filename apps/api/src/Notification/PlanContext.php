<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\ReminderRule;
use App\Entity\User;
use App\Entity\UserLocation;

/**
 * What planning needs about the user, read once per run.
 */
final readonly class PlanContext
{
    /**
     * @param list<ReminderRule> $rules
     * @param list<string>       $self
     */
    public function __construct(
        public User $user,
        public \DateTimeZone $zone,
        public array $rules,
        public array $self,
        public ?UserLocation $location,
    ) {
    }
}
