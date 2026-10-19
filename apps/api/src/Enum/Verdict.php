<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The outcome every email converges on: something to do, to hand over, or to know.
 */
enum Verdict: string
{
    case Do = 'do';
    case Delegate = 'delegate';
    case Know = 'know';
    case None = 'none';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $verdict): string => $verdict->value, self::cases());
    }
}
