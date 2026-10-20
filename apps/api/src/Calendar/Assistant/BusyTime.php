<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

/**
 * Time the user (or an attendee) cannot meet, as instants.
 */
final readonly class BusyTime
{
    /**
     * @param list<string> $references what it comes from, so a search can leave it out (the meeting being moved, the
     *                                 todo being planned again): the event id and the occurrence id for calendar events
     */
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        /** What to call it when explaining a slot: an event's title; '' for an attendee's busy time. */
        public string $label = '',
        public array $references = [],
        /** At a physical place: getting there and back takes travel time. */
        public bool $atPlace = false,
        /** Its own travel time each way, when it names one; else the user's default. */
        public ?int $travelMinutes = null,
        /** A focus block: closed as focus, without buffer or travel time around it. */
        public bool $focus = false,
    ) {
    }

    public function overlaps(\DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        return $this->start < $end && $start < $this->end;
    }
}
