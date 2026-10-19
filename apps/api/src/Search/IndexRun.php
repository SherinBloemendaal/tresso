<?php

declare(strict_types=1);

namespace App\Search;

/**
 * What one indexing run sent to Meilisearch. $lastTask is the Meilisearch task to wait for to see all of it.
 */
final readonly class IndexRun
{
    public function __construct(
        public int $indexed,
        public int $deleted,
        public ?int $lastTask,
    ) {
    }

    public static function nothing(): self
    {
        return new self(0, 0, null);
    }

    public function then(self $next): self
    {
        return new self($this->indexed + $next->indexed, $this->deleted + $next->deleted, $next->lastTask ?? $this->lastTask);
    }
}
