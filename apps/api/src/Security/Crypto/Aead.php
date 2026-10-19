<?php

declare(strict_types=1);

namespace App\Security\Crypto;

/**
 * XChaCha20-Poly1305 with a random nonce. The additional data binds a ciphertext to its owner, so a ciphertext copied
 * to another record fails to decrypt.
 */
final class Aead
{
    public static function encrypt(#[\SensitiveParameter] string $plaintext, string $additionalData, #[\SensitiveParameter] string $key): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return $nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $additionalData, $nonce, $key);
    }

    public static function decrypt(string $sealed, string $additionalData, #[\SensitiveParameter] string $key): string
    {
        $nonceLength = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (\strlen($sealed) <= $nonceLength + \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new CryptoException('Ciphertext is too short.');
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($sealed, $nonceLength), $additionalData, substr($sealed, 0, $nonceLength), $key);
        if (false === $plaintext) {
            throw new CryptoException('Ciphertext could not be authenticated.');
        }

        return $plaintext;
    }
}
