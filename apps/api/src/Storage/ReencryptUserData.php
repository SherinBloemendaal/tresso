<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Moves one user's stored data to their current key version in the background, a while at a time; the job queues
 * itself again until nothing is left ({@see ReencryptUserDataHandler}). Safe to send any number of times.
 */
final readonly class ReencryptUserData
{
    public function __construct(public string $userId)
    {
    }
}
