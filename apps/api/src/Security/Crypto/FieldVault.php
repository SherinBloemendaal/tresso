<?php

declare(strict_types=1);

namespace App\Security\Crypto;

use Symfony\Component\Uid\Uuid;

/**
 * Seals database fields with the owner's field key, bound to the field and row they belong to ($context): a sealed
 * value copied to another row, field or owner does not open.
 *
 * Sealed format: "f1.<key version>.<base64(nonce + ciphertext)>" (XChaCha20-Poly1305).
 */
final readonly class FieldVault
{
    private const string FORMAT = 'f1';

    public function __construct(private UserKeys $keys)
    {
    }

    public function seal(Uuid $owner, string $context, #[\SensitiveParameter] string $plaintext): string
    {
        $current = $this->keys->current($owner);
        $key = UserKeys::fieldKey($current->key);
        $sealed = self::FORMAT.'.'.$current->version.'.'.base64_encode(Aead::encrypt($plaintext, $context, $key));
        sodium_memzero($key);

        return $sealed;
    }

    public function open(Uuid $owner, string $context, string $sealed): string
    {
        $version = self::versionOf($sealed);
        $payload = base64_decode(explode('.', $sealed, 3)[2], true);
        if (false === $payload) {
            throw new CryptoException('Sealed field is not valid base64.');
        }
        $key = UserKeys::fieldKey($this->keys->version($owner, $version));
        $plaintext = Aead::decrypt($payload, $context, $key);
        sodium_memzero($key);

        return $plaintext;
    }

    /** Opens a sealed value, or passes on null. */
    public function openNullable(Uuid $owner, string $context, ?string $sealed): ?string
    {
        return null === $sealed ? null : $this->open($owner, $context, $sealed);
    }

    public static function isSealed(string $value): bool
    {
        return 1 === preg_match('/^'.self::FORMAT.'\.[1-9][0-9]*\./', $value);
    }

    /** The key version a sealed value was written with. */
    public static function versionOf(string $sealed): int
    {
        if (!self::isSealed($sealed)) {
            throw new CryptoException('Unsupported sealed field format.');
        }

        return (int) explode('.', $sealed, 3)[1];
    }
}
