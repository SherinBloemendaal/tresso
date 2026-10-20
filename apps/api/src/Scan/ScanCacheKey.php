<?php

declare(strict_types=1);

namespace App\Scan;

/**
 * Where a verdict for some content is cached: hex(HMAC-SHA256(scan cache key, SHA-256 of the content)), with the id of
 * the master key the scan cache key is derived from. Computed when a scan runs, never stored with an attachment, so
 * the cache cannot link the users who have the same file.
 */
final readonly class ScanCacheKey
{
    public function __construct(
        public string $contentKey,
        public string $keyId,
    ) {
    }
}
