<?php

declare(strict_types=1);

namespace App\Storage;

use Symfony\Component\Uid\Uuid;

/**
 * Object keys. Encrypted content lives under its owner and fingerprint: "<kind>/<owner id>/<fp[0:2]>/<fp>". Content
 * from before encryption lives under its plain SHA-256 ("<kind>/<sha256[0:2]>/<sha256>"), possibly shared between
 * users, until re-encryption has moved it.
 */
final readonly class BlobKey
{
    private const string ENCRYPTED = '#^([a-z]+)/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/([0-9a-f]{2})/([0-9a-f]{64})$#';
    private const string LEGACY = '#^([a-z]+)/([0-9a-f]{2})/([0-9a-f]{64})$#';

    private function __construct(
        public BlobKind $kind,
        public Uuid $owner,
        public string $fingerprint,
    ) {
    }

    public static function of(BlobKind $kind, Uuid $owner, string $fingerprint): string
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $fingerprint)) {
            throw new \InvalidArgumentException('A fingerprint is 64 hex digits.');
        }

        return $kind->value.'/'.$owner->toRfc4122().'/'.substr($fingerprint, 0, 2).'/'.$fingerprint;
    }

    /** Where content from before encryption was stored, by its plain SHA-256. */
    public static function legacy(BlobKind $kind, string $sha256): string
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            throw new \InvalidArgumentException('A SHA-256 is 64 hex digits.');
        }

        return $kind->value.'/'.substr($sha256, 0, 2).'/'.$sha256;
    }

    /** The parts of an encrypted object's key; null for any other key. */
    public static function parse(string $key): ?self
    {
        if (1 !== preg_match(self::ENCRYPTED, $key, $match) || substr($match[4], 0, 2) !== $match[3]) {
            return null;
        }
        $kind = BlobKind::tryFrom($match[1]);

        return null === $kind ? null : new self($kind, Uuid::fromString($match[2]), $match[4]);
    }

    public static function isLegacy(string $key): bool
    {
        return 1 === preg_match(self::LEGACY, $key, $match) && substr($match[3], 0, 2) === $match[2] && null !== BlobKind::tryFrom($match[1]);
    }

    /** The prefix every object of an owner of one kind starts with. */
    public static function ownerPrefix(BlobKind $kind, Uuid $owner): string
    {
        return $kind->value.'/'.$owner->toRfc4122().'/';
    }
}
