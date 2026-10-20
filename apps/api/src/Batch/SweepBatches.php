<?php

declare(strict_types=1);

namespace App\Batch;

/** Every minute: release batches whose time has come, in case their release message got lost. */
final readonly class SweepBatches
{
}
