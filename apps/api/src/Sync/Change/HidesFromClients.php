<?php

declare(strict_types=1);

namespace App\Sync\Change;

/**
 * A synced entity that can be out of sight of the clients for a while, without being deleted (a draft being sent).
 * Becoming hidden reaches clients as a delete; showing again as a change, with everything they need.
 */
interface HidesFromClients
{
    public function isHiddenFromClients(): bool;
}
