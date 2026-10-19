<?php

declare(strict_types=1);

namespace App\Sync;

final readonly class ReleaseResult
{
    public function __construct(public int $released)
    {
    }
}
