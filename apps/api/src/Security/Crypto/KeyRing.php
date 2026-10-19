<?php

declare(strict_types=1);

namespace App\Security\Crypto;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Master keys (key encryption keys) that wrap the per-user data keys.
 *
 * DATA_KEYS holds every key that may still be needed for decryption ("id:base64,id:base64"); DATA_KEY_CURRENT names
 * the one used for new wraps. Rotating: add a new key, make it current, run app:crypto:rotate-data-keys, then remove
 * the old key.
 */
final class KeyRing
{
    /** @var array<string, string> key id => raw 32-byte key */
    private array $keys = [];

    public function __construct(
        #[Autowire(env: 'DATA_KEYS')]
        #[\SensitiveParameter]
        string $keys,
        #[Autowire(env: 'DATA_KEY_CURRENT')]
        private readonly string $currentId,
    ) {
        foreach (array_filter(array_map('trim', explode(',', $keys))) as $entry) {
            $parts = explode(':', $entry, 2);
            if (2 !== \count($parts) || 1 !== preg_match('/^[a-z0-9_-]{1,32}$/i', $parts[0])) {
                throw new CryptoException('DATA_KEYS entries must look like "id:base64".');
            }
            $raw = base64_decode($parts[1], true);
            if (false === $raw || \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES !== \strlen($raw)) {
                throw new CryptoException(\sprintf('Key "%s" in DATA_KEYS must be %d bytes, base64 encoded.', $parts[0], \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
            }
            $this->keys[$parts[0]] = $raw;
        }

        if (!isset($this->keys[$this->currentId])) {
            throw new CryptoException(\sprintf('DATA_KEY_CURRENT "%s" is not present in DATA_KEYS.', $this->currentId));
        }
    }

    public function currentId(): string
    {
        return $this->currentId;
    }

    public function get(string $id): string
    {
        return $this->keys[$id] ?? throw new CryptoException(\sprintf('Unknown data key encryption key "%s".', $id));
    }
}
