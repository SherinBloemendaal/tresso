<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

/**
 * A proposed time, with why it is free: the free time it lies in and what closes that free time on each side.
 */
final readonly class Slot
{
    /**
     * @param list<string> $optionalConflicts optional attendees who are busy then
     */
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public Boundary $freeFrom,
        public Boundary $freeUntil,
        public array $optionalConflicts = [],
    ) {
    }
}
