<?php

declare(strict_types=1);

namespace App\Enum;

/** iCalendar STATUS of an event (RFC 5545 3.8.1.11). */
enum EventStatus: string
{
    case Confirmed = 'confirmed';
    case Tentative = 'tentative';
    case Cancelled = 'cancelled';

    public static function fromICalendar(?string $value): self
    {
        return match (strtoupper(trim((string) $value))) {
            'TENTATIVE' => self::Tentative,
            'CANCELLED' => self::Cancelled,
            default => self::Confirmed,
        };
    }

    public function toICalendar(): string
    {
        return strtoupper($this->value);
    }
}
