<?php

declare(strict_types=1);

namespace App\Sync\Unsubscribe;

final readonly class UnsubscribeReportResult
{
    public function __construct(public bool $accepted)
    {
    }
}
