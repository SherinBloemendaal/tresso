<?php

declare(strict_types=1);

namespace App\Notification\Channel;

/**
 * How one push went: sent, failed (try another time), or gone (the subscription or token no longer exists).
 */
final readonly class PushResult
{
    private function __construct(
        public string $status,
        public ?string $error,
    ) {
    }

    public static function sent(): self
    {
        return new self('sent', null);
    }

    public static function failed(string $error): self
    {
        return new self('failed', $error);
    }

    public static function gone(): self
    {
        return new self('gone', null);
    }
}
