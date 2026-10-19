<?php

declare(strict_types=1);

namespace App\Notification\Channel;

/**
 * What every push channel shows: a title and body, where a tap leads, and whether it may make a sound.
 */
final readonly class PushMessage
{
    public function __construct(
        public string $notificationId,
        public string $kind,
        public string $title,
        public string $body,
        /** A path in the app, e.g. /calendar/events/{id}?occurrence=... */
        public string $path,
        public bool $silent,
        /** Push services drop it when it could not be delivered by then. */
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return ['id' => $this->notificationId, 'kind' => $this->kind, 'title' => $this->title, 'body' => $this->body, 'path' => $this->path, 'silent' => $this->silent];
    }
}
