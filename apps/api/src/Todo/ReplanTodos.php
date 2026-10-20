<?php

declare(strict_types=1);

namespace App\Todo;

/**
 * Check the user's planned todos again: their calendar changed, or they just switched todo planning on ($planOpen:
 * plan the open todos that have no time yet as well).
 */
final readonly class ReplanTodos
{
    public function __construct(
        public string $userId,
        public bool $planOpen = false,
    ) {
    }
}
