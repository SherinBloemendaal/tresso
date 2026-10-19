<?php

declare(strict_types=1);

namespace App\Sync\Calendar;

final readonly class CalendarListResult
{
    /**
     * @param list<array{remoteId: string, id: string, cursor: ?string}> $calendars
     */
    public function __construct(public array $calendars)
    {
    }
}
