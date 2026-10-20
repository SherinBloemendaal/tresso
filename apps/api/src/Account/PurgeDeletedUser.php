<?php

declare(strict_types=1);

namespace App\Account;

/**
 * Removes what a deleted user left outside the database: their search documents and every object stored under their
 * id. Their keys are gone already, so all of it is unreadable; this frees the space. Safe to send any number of times.
 */
final readonly class PurgeDeletedUser
{
    public function __construct(public string $userId)
    {
    }
}
