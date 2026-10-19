<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\CalendarEvent;
use App\Enum\NotificationKind;

/**
 * One notification the planner wants for one occurrence.
 */
final readonly class Plan
{
    /**
     * @param list<string> $channels
     */
    public function __construct(
        public string $occurrenceKey,
        public NotificationKind $kind,
        public string $slot,
        public \DateTimeImmutable $fireAt,
        public \DateTimeImmutable $startsAt,
        public string $title,
        public string $body,
        public array $channels,
        /** The occurrence's start as the event writes it (Props::start). */
        public string $occurrenceStart,
    ) {
    }

    public function firingAt(\DateTimeImmutable $fireAt): self
    {
        return new self($this->occurrenceKey, $this->kind, $this->slot, $fireAt, $this->startsAt, $this->title, $this->body, $this->channels, $this->occurrenceStart);
    }

    public function key(CalendarEvent $event): string
    {
        return implode('|', [$event->getId()->toRfc4122(), $this->occurrenceKey, $this->kind->value, $this->slot]);
    }
}
