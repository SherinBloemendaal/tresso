<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Storage\ObjectNotFound;
use App\Storage\ObjectStorage;

final class InMemoryObjectStorage implements ObjectStorage
{
    /** @var array<string, string> */
    private array $objects = [];

    /** Copies and moves fail while this is set, like an object store that is down. */
    public bool $broken = false;

    /** Called at every copy and move, to look at what holds while content moves. */
    public ?\Closure $whileMoving = null;

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

    public function copy(string $from, string $to): void
    {
        $this->failIfBroken();
        $this->objects[$to] = $this->read($from);
    }

    public function move(string $from, string $to): void
    {
        $this->failIfBroken();
        $this->objects[$to] = $this->read($from);
        unset($this->objects[$from]);
    }

    public function stream(string $key): iterable
    {
        return str_split($this->read($key), 8192);
    }

    private function failIfBroken(): void
    {
        if (null !== $this->whileMoving) {
            ($this->whileMoving)();
        }
        if ($this->broken) {
            throw new \RuntimeException('Object storage is down.');
        }
    }
}
