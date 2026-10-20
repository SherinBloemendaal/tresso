<?php

declare(strict_types=1);

namespace App\Scan;

/**
 * Queues scans again for attachments still pending well after their scan was queued (or that never had one queued,
 * such as attachments stored before scanning existed). Sent by the schedule every few minutes.
 */
final readonly class SweepPendingScans
{
}
