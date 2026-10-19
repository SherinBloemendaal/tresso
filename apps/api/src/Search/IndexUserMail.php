<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Asks for a user's search documents to catch up with their committed changes. Safe to send any number of times.
 */
final readonly class IndexUserMail
{
    public function __construct(public string $userId)
    {
    }
}
