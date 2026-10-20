<?php

declare(strict_types=1);

namespace App\Brief;

/**
 * Sends a brief that was made on the channels the user chose besides the app, once it is committed.
 */
final readonly class DeliverDailyBrief
{
    public function __construct(public string $briefId)
    {
    }
}
