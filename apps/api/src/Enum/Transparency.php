<?php

declare(strict_types=1);

namespace App\Enum;

/** Whether an event blocks time (iCalendar TRANSP). */
enum Transparency: string
{
    case Busy = 'busy';
    case Free = 'free';

    public static function fromICalendar(?string $value): self
    {
        return 'TRANSPARENT' === strtoupper(trim((string) $value)) ? self::Free : self::Busy;
    }

    public function toICalendar(): string
    {
        return self::Free === $this ? 'TRANSPARENT' : 'OPAQUE';
    }
}
