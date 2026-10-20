<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

final readonly class SlotSearchResult
{
    /**
     * @param list<Slot> $slots best first
     */
    public function __construct(
        public array $slots,
        /** Set when there are no slots. */
        public ?NoSlotReason $noSlotReason = null,
    ) {
    }
}
