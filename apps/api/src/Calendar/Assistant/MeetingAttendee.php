<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

final readonly class MeetingAttendee
{
    public function __construct(
        /** Lower case. */
        public string $address,
        /** An optional attendee's busy time does not rule a slot out; it ranks the slot lower. */
        public bool $optional = false,
    ) {
    }
}
