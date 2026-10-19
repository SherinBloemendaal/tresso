<?php

declare(strict_types=1);

namespace App\ProviderAuth;

/**
 * Who signed in at the provider.
 */
final readonly class ProviderIdentity
{
    public function __construct(
        public string $subject,
        public string $emailAddress,
        public ?string $displayName,
    ) {
    }
}
