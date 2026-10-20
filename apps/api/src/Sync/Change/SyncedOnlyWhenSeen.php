<?php

declare(strict_types=1);

namespace App\Sync\Change;

/**
 * A synced entity that clients see only in some states (a notification once it reached a device). While it is not
 * seen, its inserts, updates and deletes are no change for clients: no change number, no tombstone, no realtime ping.
 */
interface SyncedOnlyWhenSeen extends TracksChanges
{
    public function isSeenByClients(): bool;
}
