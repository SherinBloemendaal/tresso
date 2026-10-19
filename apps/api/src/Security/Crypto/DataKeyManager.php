<?php

declare(strict_types=1);

namespace App\Security\Crypto;

use App\Entity\User;

/**
 * Per-user data keys (DEKs), stored on the user wrapped by a master key from the KeyRing.
 *
 * Wrapped format: "v1.<key id>.<base64(nonce + ciphertext)>", with the user id as additional data.
 */
final readonly class DataKeyManager
{
    private const string VERSION = 'v1';

    public function __construct(private KeyRing $keyRing)
    {
    }

    /**
     * Returns the user's raw data key, creating and wrapping one on first use. The caller flushes the entity manager.
     */
    public function dataKeyFor(User $user): string
    {
        $wrapped = $user->getWrappedDataKey();
        if (null === $wrapped) {
            $key = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
            $user->setWrappedDataKey($this->wrap($user, $key));

            return $key;
        }

        return $this->unwrap($user, $wrapped);
    }

    /**
     * Re-wraps the user's data key with the current master key. Returns false when it already uses that key.
     */
    public function rewrap(User $user): bool
    {
        $wrapped = $user->getWrappedDataKey();
        if (null === $wrapped || $this->keyIdOf($wrapped) === $this->keyRing->currentId()) {
            return false;
        }

        $key = $this->unwrap($user, $wrapped);
        $user->setWrappedDataKey($this->wrap($user, $key));
        sodium_memzero($key);

        return true;
    }

    private function wrap(User $user, #[\SensitiveParameter] string $key): string
    {
        $keyId = $this->keyRing->currentId();

        return self::VERSION.'.'.$keyId.'.'.base64_encode(Aead::encrypt($key, $this->additionalData($user), $this->keyRing->get($keyId)));
    }

    private function unwrap(User $user, string $wrapped): string
    {
        $keyId = $this->keyIdOf($wrapped);
        $payload = base64_decode(explode('.', $wrapped, 3)[2], true);
        if (false === $payload) {
            throw new CryptoException('Wrapped data key is not valid base64.');
        }

        return Aead::decrypt($payload, $this->additionalData($user), $this->keyRing->get($keyId));
    }

    private function keyIdOf(string $wrapped): string
    {
        $parts = explode('.', $wrapped, 3);
        if (3 !== \count($parts) || self::VERSION !== $parts[0]) {
            throw new CryptoException('Unsupported wrapped data key format.');
        }

        return $parts[1];
    }

    private function additionalData(User $user): string
    {
        return 'tresso:user-data-key:'.$user->getId()->toRfc4122();
    }
}
