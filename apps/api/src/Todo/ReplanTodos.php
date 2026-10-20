<?php

declare(strict_types=1);

namespace App\Todo;

/**
 * Check the user's todos against their calendar again: something planned time depends on changed (see
 * {@see TodoReplanning}).
 */
final readonly class ReplanTodos
{
    public function __construct(public string $userId)
    {
    }
}
