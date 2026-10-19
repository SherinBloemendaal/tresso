<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Mail\Autoconfig\MxResolver;

final class StaticMxResolver extends MxResolver
{
    /** @var array<string, list<string>> */
    private array $records = [];

    /** @param list<string> $exchangers */
    public function set(string $domain, array $exchangers): void
    {
        $this->records[$domain] = $exchangers;
    }

    public function resolve(string $domain): array
    {
        return $this->records[$domain] ?? [];
    }
}
