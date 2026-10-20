<?php

declare(strict_types=1);

namespace App\HandOver;

/**
 * Delivered at a hand-over's due date: if nobody replied by then, the user is told.
 */
final readonly class HandOverDue
{
    public function __construct(public string $handOverId)
    {
    }
}
