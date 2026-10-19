<?php

declare(strict_types=1);

namespace App\Enum;

enum OutgoingStatus: string
{
    /** Waiting for its send time (send later, or the undo window). */
    case Scheduled = 'scheduled';
    /** Due; a sync worker will pick it up. */
    case Queued = 'queued';
    /** Claimed by a worker that is sending it. */
    case Sending = 'sending';
    case Sent = 'sent';
    /** Refused by the server, or still failing after every retry. */
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return \in_array($this, [self::Sent, self::Failed, self::Cancelled], true);
    }
}
