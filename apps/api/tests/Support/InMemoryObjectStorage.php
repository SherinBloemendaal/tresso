<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Storage\ObjectNotFound;
use App\Storage\ObjectStorage;

final class InMemoryObjectStorage implements ObjectStorage
{
    /** @var array<string, string> */
    private array $objects = [];

    public function put(string $key, string $content): void
    {
        $this->objects[$key] = $content;
    }

    public function read(string $key): string
    {
        return $this->objects[$key] ?? throw ObjectNotFound::forKey($key);
    }

    public function stream(string $key): iterable
    {
        return str_split($this->read($key), 8192);
    }
}
