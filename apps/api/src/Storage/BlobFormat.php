<?php

declare(strict_types=1);

namespace App\Storage;

use App\Security\Crypto\CryptoException;
use Symfony\Component\Uid\Uuid;

/**
 * The encrypted object format, shared with the sync worker (apps/sync/internal/blobcrypt):
 *
 *     header: "TRB1" | key version (uint32, big endian) | salt (32 random bytes)
 *     frames: XChaCha20-Poly1305 of 64 KiB of content each, the last one possibly shorter (or empty)
 *
 * Frame key: HKDF-SHA256(blob key, salt, "tresso:blob-frame:v1"). Frame nonce: 15 zero bytes, the frame number
 * (uint64, big endian), then 1 on the last frame and 0 on the others. Additional data: the header, the owner's id (16
 * bytes) and the content fingerprint (32 bytes). So frames cannot be dropped, reordered, cut off or moved to another
 * object, owner or key version, and every frame is authenticated before its content is handed on.
 */
final class BlobFormat
{
    public const string MAGIC = 'TRB1';
    public const int HEADER_BYTES = 40;
    public const int FRAME_BYTES = 65536;
    private const int TAG_BYTES = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

    /**
     * Encrypts content into $out and returns the number of bytes written.
     *
     * @param iterable<string> $content
     * @param resource         $out
     */
    public static function encrypt(#[\SensitiveParameter] string $blobKey, int $version, Uuid $owner, string $fingerprint, iterable $content, $out): int
    {
        $header = self::MAGIC.pack('N', $version).random_bytes(32);
        $frameKey = self::frameKey($blobKey, $header);
        $additionalData = $header.$owner->toBinary().self::fingerprintBytes($fingerprint);
        $written = self::write($out, $header);

        $buffer = '';
        $frame = 0;
        foreach ($content as $chunk) {
            $buffer .= $chunk;
            $offset = 0;
            // A frame is only written once more content follows, so the last one is known when it is written.
            while (\strlen($buffer) - $offset > self::FRAME_BYTES) {
                $written += self::write($out, self::seal(substr($buffer, $offset, self::FRAME_BYTES), $frame++, false, $additionalData, $frameKey));
                $offset += self::FRAME_BYTES;
            }
            $buffer = substr($buffer, $offset);
        }
        $written += self::write($out, self::seal($buffer, $frame, true, $additionalData, $frameKey));
        sodium_memzero($frameKey);

        return $written;
    }

    /**
     * Decrypts an object streamed in chunks of any size, frame by frame.
     *
     * @param iterable<string>     $ciphertext
     * @param callable(int):string $blobKeyFor the owner's blob key of a key version
     *
     * @return \Generator<int, string>
     */
    public static function decrypt(iterable $ciphertext, callable $blobKeyFor, Uuid $owner, string $fingerprint): \Generator
    {
        $sealedFrame = self::FRAME_BYTES + self::TAG_BYTES;
        $buffer = '';
        $header = null;
        $frameKey = '';
        $additionalData = '';
        $frame = 0;

        foreach ($ciphertext as $chunk) {
            $buffer .= $chunk;
            if (null === $header) {
                if (\strlen($buffer) < self::HEADER_BYTES) {
                    continue;
                }
                $header = substr($buffer, 0, self::HEADER_BYTES);
                $buffer = substr($buffer, self::HEADER_BYTES);
                $frameKey = self::frameKey($blobKeyFor(self::version($header)), $header);
                $additionalData = $header.$owner->toBinary().self::fingerprintBytes($fingerprint);
            }
            $offset = 0;
            while (\strlen($buffer) - $offset > $sealedFrame) {
                yield self::open(substr($buffer, $offset, $sealedFrame), $frame++, false, $additionalData, $frameKey);
                $offset += $sealedFrame;
            }
            $buffer = substr($buffer, $offset);
        }

        if (null === $header) {
            throw new CryptoException('The object is shorter than its header.');
        }
        $last = self::open($buffer, $frame, true, $additionalData, $frameKey);
        sodium_memzero($frameKey);
        if ('' !== $last) {
            yield $last;
        }
    }

    /** The key version an object was written with, from its header. */
    public static function version(string $header): int
    {
        if (\strlen($header) < self::HEADER_BYTES || self::MAGIC !== substr($header, 0, 4)) {
            throw new CryptoException('Not an encrypted object.');
        }
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', substr($header, 4, 4));

        return $unpacked[1];
    }

    private static function frameKey(#[\SensitiveParameter] string $blobKey, string $header): string
    {
        return hash_hkdf('sha256', $blobKey, 32, 'tresso:blob-frame:v1', substr($header, 8, 32));
    }

    private static function nonce(int $frame, bool $last): string
    {
        return str_repeat("\0", 15).pack('J', $frame).($last ? "\1" : "\0");
    }

    private static function seal(string $content, int $frame, bool $last, string $additionalData, #[\SensitiveParameter] string $frameKey): string
    {
        return sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($content, $additionalData, self::nonce($frame, $last), $frameKey);
    }

    private static function open(string $sealed, int $frame, bool $last, string $additionalData, #[\SensitiveParameter] string $frameKey): string
    {
        $content = \strlen($sealed) < self::TAG_BYTES ? false : sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($sealed, $additionalData, self::nonce($frame, $last), $frameKey);
        if (false === $content) {
            throw new CryptoException(\sprintf('Frame %d of the object could not be authenticated.', $frame));
        }

        return $content;
    }

    private static function fingerprintBytes(string $fingerprint): string
    {
        $bytes = 1 === preg_match('/^[0-9a-f]{64}$/', $fingerprint) ? hex2bin($fingerprint) : false;

        return false === $bytes ? throw new \InvalidArgumentException('A fingerprint is 64 hex digits.') : $bytes;
    }

    /**
     * @param resource $out
     */
    private static function write($out, string $bytes): int
    {
        if (false === fwrite($out, $bytes)) {
            throw new \RuntimeException('The encrypted object could not be written.');
        }

        return \strlen($bytes);
    }
}
