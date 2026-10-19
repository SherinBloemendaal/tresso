<?php

declare(strict_types=1);

namespace App\Enum;

/** iCalendar CLASS of an event. */
enum EventVisibility: string
{
    case Public = 'public';
    case Private = 'private';
    case Confidential = 'confidential';

    public static function fromICalendar(?string $value): self
    {
        return match (strtoupper(trim((string) $value))) {
            'PRIVATE' => self::Private,
            'CONFIDENTIAL' => self::Confidential,
            default => self::Public,
        };
    }

    public function toICalendar(): string
    {
        return strtoupper($this->value);
    }
}
