<?php

declare(strict_types=1);

namespace App\ProviderAuth;

final readonly class AccessToken
{
    public function __construct(
        #[\SensitiveParameter]
        public string $token,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
