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

    /** Called at every write before the content is read, to look at what holds while content is written. */
    public ?\Closure $whileWriting = null;

    public function put(string $key, string $content): void
    {
        $this->objects[$key] = $content;
    }

    public function read(string $key): string
    {
        return $this->objects[$key] ?? throw ObjectNotFound::forKey($key);
    }

    /** @var array<string, array<string, string>> */
    public array $metadata = [];

    public function write(string $key, mixed $content, string $contentType, array $metadata = []): void
    {
        if (null !== $this->whileWriting) {
            ($this->whileWriting)($key);
        }
        if (\is_resource($content)) {
            $content = stream_get_contents($content);
        }
        if (!\is_string($content)) {
            throw new \InvalidArgumentException('Object content is a string or a stream.');
        }
        $this->objects[$key] = $content;
        $this->metadata[$key] = $metadata;
    }

    public function list(string $prefix): iterable
    {
        $keys = array_values(array_filter(array_keys($this->objects), static fn (string $key): bool => str_starts_with($key, $prefix)));
        sort($keys);

        return $keys;
    }

    /**
     * Every key, for tests that look at what is stored.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_keys($this->objects);
        sort($keys);

        return $keys;
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }

    public function exists(string $key): bool
    {
        return $this->has($key);
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
