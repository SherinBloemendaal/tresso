<?php

declare(strict_types=1);

namespace App\HandOver;

/**
 * Sent after a hand-over became overdue: tells the user on the channels they chose for hand-overs.
 */
final readonly class NotifyOverdueHandOver
{
    public function __construct(public string $handOverId)
    {
    }
}
