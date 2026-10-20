<?php

declare(strict_types=1);

namespace App\Sync\Unsubscribe;

/**
 * One-click requests the worker must make: a POST of `List-Unsubscribe=One-Click` to each HTTPS `url`, then a report.
 */
final readonly class UnsubscribeWork
{
    /**
     * @param list<array{id: string, url: string}> $requests
     */
    public function __construct(public array $requests)
    {
    }
}
