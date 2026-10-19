<?php

declare(strict_types=1);

namespace App\Outbox;

/**
 * Delivered at a scheduled message's send time (send later, the undo window, a retry): makes it due for sending.
 */
final readonly class ReleaseOutgoingMessage
{
    public function __construct(public string $outgoingMessageId)
    {
    }
}
