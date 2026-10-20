<?php

declare(strict_types=1);

namespace App\ApiResource\Output;

use App\Calendar\Assistant\Boundary;

/**
 * One side of a slot's free time and what closes it there.
 */
final readonly class SlotEdge
{
    public function __construct(
        public \DateTimeImmutable $at,
        /** window, notice, working_hours, focus, busy, buffer, travel or attendee. */
        public string $reason,
        /** The busy time's title, the focus block's label or the attendee's address; '' when there is nothing to name. */
        public string $label,
    ) {
    }

    public static function fromBoundary(Boundary $boundary): self
    {
        return new self($boundary->at, $boundary->kind->value, $boundary->label);
    }
}
