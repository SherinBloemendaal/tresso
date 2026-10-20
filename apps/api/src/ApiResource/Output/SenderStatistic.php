<?php

declare(strict_types=1);

namespace App\ApiResource\Output;

/** One sender of a smart inbox's conversations and how much of their mail is unread. */
final readonly class SenderStatistic
{
    public function __construct(
        public string $address,
        public ?string $name,
        public int $conversations,
        public int $messages,
        public int $unread,
        public \DateTimeImmutable $lastReceivedAt,
    ) {
    }
}
