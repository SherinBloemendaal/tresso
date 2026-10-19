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

    public function write(string $key, string $content, string $contentType): void
    {
        $this->objects[$key] = $content;
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }

    public function has(string $key): bool
    {
        return isset($this->objects[$key]);
    }

    public function stream(string $key): iterable
    {
        return str_split($this->read($key), 8192);
    }
}
