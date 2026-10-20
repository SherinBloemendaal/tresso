<?php

declare(strict_types=1);

namespace App\Storage;

/** Content {@see BlobVault} stored: where, under which fingerprint, with which key version, and how large it is. */
final readonly class StoredBlob
{
    public function __construct(
        public string $key,
        public string $fingerprint,
        public int $keyVersion,
        public int $size,
    ) {
    }
}
