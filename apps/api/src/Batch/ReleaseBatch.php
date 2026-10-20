<?php

declare(strict_types=1);

namespace App\Batch;

/**
 * Delivered at a batch time: releases the user's held mail whose batch has come.
 */
final readonly class ReleaseBatch
{
    public function __construct(public string $userId)
    {
    }
}
