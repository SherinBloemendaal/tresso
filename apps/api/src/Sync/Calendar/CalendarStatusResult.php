<?php

declare(strict_types=1);

namespace App\Sync\Calendar;

final readonly class CalendarStatusResult
{
    public function __construct(public string $status)
    {
    }
}
