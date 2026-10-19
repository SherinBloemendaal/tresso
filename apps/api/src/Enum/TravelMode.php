<?php

declare(strict_types=1);

namespace App\Enum;

/** How the user usually travels, for leave-now estimates. */
enum TravelMode: string
{
    case Walk = 'walk';
    case Bike = 'bike';
    case Transit = 'transit';
    case Car = 'car';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
