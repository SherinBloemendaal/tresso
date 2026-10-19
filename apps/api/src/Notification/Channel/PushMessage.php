<?php

declare(strict_types=1);

namespace App\Notification\Channel;

/**
 * What every channel shows: a title and body, where a tap leads, and whether it may make a sound. Any feature can
 * send one; the id is what clients dismiss it by.
 */
final readonly class PushMessage
{
    /**
     * @param string|null           $path a path in the app (/calendar/events/{id}?occurrence=..., /thread/{id}), or
     *                                    null to open the app as it is
     * @param array<string, string> $data more for the client to read, next to the fixed keys
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $title,
        public string $body,
        public ?string $path,
        public bool $silent,
        /** Push services drop it when it could not be delivered by then. */
        public \DateTimeImmutable $expiresAt,
        public array $data = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [...$this->data, 'id' => $this->id, 'kind' => $this->kind, 'title' => $this->title, 'body' => $this->body, 'path' => $this->path, 'silent' => $this->silent];
    }
}
