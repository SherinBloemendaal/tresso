<?php

declare(strict_types=1);

namespace App\Classification;

/**
 * Asks for a user's messages that were not classified yet to be classified. Safe to send any number of times.
 */
final readonly class ClassifyNewMail
{
    public function __construct(public string $userId)
    {
    }
}
