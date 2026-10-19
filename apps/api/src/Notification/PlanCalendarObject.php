<?php

declare(strict_types=1);

namespace App\Notification;

/** Plan the notifications of one calendar object again (it was saved or removed). */
final readonly class PlanCalendarObject
{
    public function __construct(
        public string $objectId,
        public string $userId,
    ) {
    }
}
