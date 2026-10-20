<?php

declare(strict_types=1);

namespace App\Storage;

use App\Security\Crypto\CryptoException;
use App\Security\Crypto\UserKeys;
use Symfony\Component\Uid\Uuid;

/**
 * Stores and reads mail content encrypted per user ({@see BlobFormat}), under the owner's id and the content's
 * fingerprint ({@see BlobKey}). Reading takes the owner the database row belongs to: an object under another owner's
 * name, or one that fails authentication, is refused. Content from before encryption (a legacy key) is read as it is.
 *
 * Nothing holds a whole object in memory: content is encrypted into a temporary stream that spills to disk, and
 * decrypted frame by frame while it is read.
 */
final readonly class BlobVault
{
    /** Object metadata naming the key version, so a sync worker that finds the object stored can tell it is current. */
    public const string KEY_VERSION_METADATA = 'tresso-key-version';

    private const int SPILL_BYTES = 2 * 1024 * 1024;
    private const int READ_BYTES = 1024 * 1024;

    public function __construct(
        private ObjectStorage $storage,
        private UserKeys $keys,
    ) {
    }

    /**
     * Encrypts and stores content with the owner's current key, replacing what is under that name.
     *
     * @param string|resource $content a resource is read from its start; it must be seekable
     */
    public function store(Uuid $owner, BlobKind $kind, mixed $content): StoredBlob
    {
        $fingerprint = $this->keys->fingerprint($owner, self::sha256($content));
        $current = $this->keys->current($owner);
        $blobKey = UserKeys::blobKey($current->key);
        $out = fopen('php://temp/maxmemory:'.self::SPILL_BYTES, 'w+b') ?: throw new \RuntimeException('No temporary stream for encryption.');
        try {
            $size = 0;
            BlobFormat::encrypt($blobKey, $current->version, $owner, $fingerprint, self::chunks($content, $size), $out);
            sodium_memzero($blobKey);
            rewind($out);
            $key = BlobKey::of($kind, $owner, $fingerprint);
            $this->storage->write($key, $out, 'application/octet-stream', [self::KEY_VERSION_METADATA => (string) $current->version]);
        } finally {
            fclose($out);
        }

        return new StoredBlob($key, $fingerprint, $current->version, $size);
    }

    /**
     * Opens an object for streaming. A missing object, a wrong owner and a first frame that fails authentication fail
     * here, before anything is handed on; a later frame that fails ends the stream with an exception.
     *
     * @return iterable<string>
     *
     * @throws ObjectNotFound
     * @throws CryptoException
     */
    public function stream(Uuid $owner, string $key): iterable
    {
        if (BlobKey::isLegacy($key)) {
            return $this->storage->stream($key);
        }
        $parsed = BlobKey::parse($key) ?? throw new CryptoException(\sprintf('"%s" is not an object key.', $key));
        if (!$parsed->owner->equals($owner)) {
            throw new CryptoException(\sprintf('Object "%s" belongs to another user.', $key));
        }

        $content = BlobFormat::decrypt(
            $this->storage->stream($key),
            fn (int $version): string => UserKeys::blobKey($this->keys->version($owner, $version)),
            $owner,
            $parsed->fingerprint,
        );
        $content->current();

        return self::resume($content);
    }

    /**
     * @throws ObjectNotFound
     * @throws CryptoException
     */
    public function read(Uuid $owner, string $key): string
    {
        $content = '';
        foreach ($this->stream($owner, $key) as $chunk) {
            $content .= $chunk;
        }

        return $content;
    }

    /**
     * Encrypts an object again with the owner's current key, also one from before encryption, and returns where it
     * is now (legacy content moves to its encrypted name; the old object is left to the caller).
     *
     * @throws ObjectNotFound
     * @throws CryptoException
     */
    public function reencrypt(Uuid $owner, BlobKind $kind, string $key): StoredBlob
    {
        $plain = fopen('php://temp/maxmemory:'.self::SPILL_BYTES, 'w+b') ?: throw new \RuntimeException('No temporary stream for re-encryption.');
        try {
            foreach ($this->stream($owner, $key) as $chunk) {
                fwrite($plain, $chunk);
            }

            return $this->store($owner, $kind, $plain);
        } finally {
            fclose($plain);
        }
    }

    /** The owner's fingerprint of content with this SHA-256 (hex). */
    public function fingerprint(Uuid $owner, string $sha256): string
    {
        $raw = 1 === preg_match('/^[0-9a-f]{64}$/', $sha256) ? hex2bin($sha256) : false;

        return $this->keys->fingerprint($owner, false === $raw ? throw new \InvalidArgumentException('A SHA-256 is 64 hex digits.') : $raw);
    }

    /**
     * @param string|resource $content
     */
    private static function sha256(mixed $content): string
    {
        if (\is_string($content)) {
            return hash('sha256', $content, true);
        }
        if (!\is_resource($content)) {
            throw new \InvalidArgumentException('Content is a string or a stream.');
        }
        rewind($content);
        $context = hash_init('sha256');
        hash_update_stream($context, $content);

        return hash_final($context, true);
    }

    /**
     * @param string|resource $content
     *
     * @return \Generator<int, string>
     */
    private static function chunks(mixed $content, int &$size): \Generator
    {
        if (\is_string($content)) {
            $size = \strlen($content);
            yield $content;

            return;
        }
        if (!\is_resource($content)) {
            throw new \InvalidArgumentException('Content is a string or a stream.');
        }
        rewind($content);
        while (!feof($content)) {
            $chunk = fread($content, self::READ_BYTES);
            if (false === $chunk) {
                throw new \RuntimeException('The content could not be read.');
            }
            $size += \strlen($chunk);
            yield $chunk;
        }
    }

    /**
     * @param \Generator<int, string> $started
     *
     * @return \Generator<int, string>
     */
    private static function resume(\Generator $started): \Generator
    {
        while ($started->valid()) {
            yield $started->current();
            $started->next();
        }
    }
}
