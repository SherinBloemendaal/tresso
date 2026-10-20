<?php

declare(strict_types=1);

namespace App\Security\Crypto;

/** A user key and its version. */
final readonly class KeyVersion
{
    public function __construct(
        public int $version,
        #[\SensitiveParameter]
        public string $key,
    ) {
    }
}
