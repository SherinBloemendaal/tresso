<?php

declare(strict_types=1);

namespace App\Realtime;

final readonly class Token
{
    public function __construct(
        public string $value,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
