<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Every hour: queue re-encryption for users whose data is not all on their current key yet (after a deploy, a key
 * rotation, or a job that stopped), see {@see ReencryptUserDataHandler::onSweep()}.
 */
final readonly class SweepReencryption
{
}
