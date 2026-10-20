<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

final readonly class MeetingAttendee
{
    /** Lower case and trimmed, as attendee addresses are compared everywhere. */
    public string $address;

    public function __construct(
        string $address,
        /** An optional attendee's busy time does not rule a slot out; it ranks the slot lower. */
        public bool $optional = false,
    ) {
        $this->address = mb_strtolower(trim($address));
    }
}
