<?php

declare(strict_types=1);

namespace App\Outbox;

final readonly class ComposedMessage
{
    /** @param string $messageId without angle brackets */
    public function __construct(
        public string $raw,
        public string $messageId,
    ) {
    }
}
