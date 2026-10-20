<?php

declare(strict_types=1);

namespace App\Scan;

use App\Entity\ScanResult;
use App\Repository\ScanResultRepository;
use App\Security\Crypto\KeyRing;
use App\Security\Crypto\UserKeys;
use App\Storage\BlobVault;
use App\Storage\ObjectNotFound;

/**
 * The verdict cache shared by all users, keyed so that it reveals nothing about who has which file
 * ({@see ScanCacheKey}). Encrypted content is decrypted once to compute the key; content from before encryption is
 * known by its plain SHA-256 already.
 *
 * That pass also checks the fingerprint the sync worker reported for the attachment: the content must be what it is
 * named by. The verdict itself never depends on the name (the cache key comes from the content), so a wrong name can
 * only mix up one user's own files; it is refused all the same, as a sign of a broken or misbehaving worker.
 */
final readonly class ScanCache
{
    public function __construct(
        private KeyRing $keyRing,
        private BlobVault $blobs,
        private UserKeys $keys,
        private ScanResultRepository $results,
    ) {
    }

    /**
     * @throws ObjectNotFound
     * @throws ContentMismatch when encrypted content is not what its fingerprint says
     */
    public function keyFor(ScanSubject $subject): ScanCacheKey
    {
        $cache = $this->keyRing->scanCacheKey();
        if ($subject->encrypted) {
            $context = hash_init('sha256');
            foreach (ScanContent::stream($this->blobs, $subject) as $chunk) {
                hash_update($context, $chunk);
            }
            $sha256 = hash_final($context, true);
            if (!hash_equals($subject->sha256, $this->keys->fingerprint($subject->owner, $sha256))) {
                throw new ContentMismatch(\sprintf('The content stored as %s does not match that fingerprint.', $subject->sha256));
            }
        } else {
            $sha256 = hex2bin($subject->sha256) ?: throw new \LogicException('Content from before encryption is known by its SHA-256.');
        }
        $key = new ScanCacheKey(hash_hmac('sha256', $sha256, $cache['key']), $cache['id']);
        sodium_memzero($cache['key']);

        return $key;
    }

    public function find(ScanCacheKey $key): ?ScanResult
    {
        $result = $this->results->find($key->contentKey);

        return null !== $result && $result->getKeyId() === $key->keyId ? $result : null;
    }
}
