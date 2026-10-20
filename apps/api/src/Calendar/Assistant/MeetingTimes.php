<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

/**
 * Proposed times and everything they were found with, so a client can explain them.
 */
final readonly class MeetingTimes
{
    /**
     * @param list<Slot>          $slots                best first
     * @param array<string, bool> $attendeeAvailability per attendee address whether their busy time was known
     */
    public function __construct(
        public array $slots,
        public ?NoSlotReason $noSlotReason,
        public SchedulingRules $rules,
        public array $attendeeAvailability,
    ) {
    }
}
