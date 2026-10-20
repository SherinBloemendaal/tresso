<?php

declare(strict_types=1);

namespace App\ApiResource\Output;

use App\Calendar\Assistant\Slot;

final readonly class ProposedSlot
{
    /**
     * @param list<string> $optionalConflicts optional attendees who are busy then
     */
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        /** Where the free time this slot lies in begins, and why it does not begin earlier. */
        public SlotEdge $freeFrom,
        /** Where that free time ends, and why. */
        public SlotEdge $freeUntil,
        public array $optionalConflicts,
    ) {
    }

    public static function fromSlot(Slot $slot): self
    {
        return new self($slot->start, $slot->end, SlotEdge::fromBoundary($slot->freeFrom), SlotEdge::fromBoundary($slot->freeUntil), $slot->optionalConflicts);
    }
}
