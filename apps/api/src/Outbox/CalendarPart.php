<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Enum\ItipMethod;

/**
 * An iTIP calendar object to send along with a message (iMIP, RFC 6047): invitations, cancellations and replies.
 */
final readonly class CalendarPart
{
    public function __construct(
        public ItipMethod $method,
        public string $ical,
    ) {
    }
}
