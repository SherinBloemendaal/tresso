<?php

declare(strict_types=1);

namespace App\ProviderAuth;

/**
 * A token endpoint response.
 */
final readonly class TokenSet
{
    /**
     * @param list<string>|null $scopes normalized; null when the provider did not say (then the request's scopes apply)
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $accessToken,
        public \DateTimeImmutable $expiresAt,
        /** Null when the provider kept the previous one. */
        #[\SensitiveParameter]
        public ?string $refreshToken,
        public ?array $scopes,
        #[\SensitiveParameter]
        public ?string $idToken,
    ) {
    }
}
