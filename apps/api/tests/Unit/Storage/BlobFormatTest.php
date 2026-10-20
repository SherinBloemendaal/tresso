<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Security\Crypto\CryptoException;
use App\Storage\BlobFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class BlobFormatTest extends TestCase
{
    /** Read by the sync worker's TestReadsWhatTheAPIWrites; regenerate with WRITE_BLOB_FIXTURE=1. */
    private const string PHP_FIXTURE = __DIR__.'/../../../../sync/internal/blobcrypt/testdata/php-object.json';
    /** Written by the sync worker's TestWorkerFixture. */
    private const string GO_FIXTURE = __DIR__.'/../../Fixtures/go-object.json';

    private Uuid $owner;
    private string $blobKey;
    private string $fingerprint;

    protected function setUp(): void
    {
        $this->owner = Uuid::fromString('0192d7a0-0000-7000-8000-000000000001');
        $this->blobKey = random_bytes(32);
        $this->fingerprint = bin2hex(random_bytes(32));
    }

    /**
     * @return iterable<string, array{int, int<1, max>}>
     */
    public static function sizes(): iterable
    {
        foreach ([0, 1, BlobFormat::FRAME_BYTES - 1, BlobFormat::FRAME_BYTES, BlobFormat::FRAME_BYTES + 1, 3 * BlobFormat::FRAME_BYTES + 17] as $size) {
            foreach ([1, 4096, 100_000] as $chunk) {
                yield $size.' bytes in chunks of '.$chunk => [$size, $chunk];
            }
        }
    }

    /**
     * @param int<1, max> $chunk
     */
    #[DataProvider('sizes')]
    public function testRoundTripsWhateverTheChunks(int $size, int $chunk): void
    {
        $content = random_bytes(max(1, $size));
        $content = substr($content, 0, $size);
        $sealed = $this->seal($content, 3);

        $frames = intdiv($size, BlobFormat::FRAME_BYTES) + 1 - (($size > 0 && 0 === $size % BlobFormat::FRAME_BYTES) ? 1 : 0);
        self::assertSame(BlobFormat::HEADER_BYTES + $size + 16 * $frames, \strlen($sealed));
        self::assertSame(3, BlobFormat::version($sealed));
        self::assertSame($content, $this->open(str_split($sealed, $chunk)));
    }

    public function testEveryObjectGetsItsOwnSalt(): void
    {
        self::assertNotSame($this->seal('same', 1), $this->seal('same', 1));
    }

    /**
     * @return iterable<string, array{\Closure(string): string}>
     */
    public static function tamperings(): iterable
    {
        $frame = BlobFormat::FRAME_BYTES + 16;
        yield 'flipped content bit' => [static fn (string $s): string => self::flip($s, BlobFormat::HEADER_BYTES + 100)];
        yield 'flipped header bit' => [static fn (string $s): string => self::flip($s, 12)];
        yield 'cut off after a frame' => [static fn (string $s): string => substr($s, 0, BlobFormat::HEADER_BYTES + $frame)];
        yield 'last byte dropped' => [static fn (string $s): string => substr($s, 0, -1)];
        yield 'frames swapped' => [static fn (string $s): string => substr($s, 0, BlobFormat::HEADER_BYTES).substr($s, BlobFormat::HEADER_BYTES + $frame, $frame).substr($s, BlobFormat::HEADER_BYTES, $frame).substr($s, BlobFormat::HEADER_BYTES + 2 * $frame)];
        yield 'a frame appended' => [static fn (string $s): string => $s.substr($s, BlobFormat::HEADER_BYTES, $frame)];
        yield 'header only' => [static fn (string $s): string => substr($s, 0, BlobFormat::HEADER_BYTES)];
        yield 'shorter than a header' => [static fn (string $s): string => substr($s, 0, 20)];
    }

    /**
     * @param \Closure(string): string $tamper
     */
    #[DataProvider('tamperings')]
    public function testTamperingIsDetected(\Closure $tamper): void
    {
        $sealed = $this->seal(str_repeat('mail ', (int) (2.5 * BlobFormat::FRAME_BYTES / 5)), 1);

        $this->expectException(CryptoException::class);
        $this->open([$tamper($sealed)]);
    }

    public function testBoundToOwnerFingerprintAndKey(): void
    {
        $sealed = $this->seal('Subject: hi', 1);
        $attempts = [
            'another owner' => fn (): string => self::join(BlobFormat::decrypt([$sealed], fn (): string => $this->blobKey, Uuid::v7(), $this->fingerprint)),
            'another fingerprint' => fn (): string => self::join(BlobFormat::decrypt([$sealed], fn (): string => $this->blobKey, $this->owner, bin2hex(random_bytes(32)))),
            'another key' => fn (): string => self::join(BlobFormat::decrypt([$sealed], static fn (): string => random_bytes(32), $this->owner, $this->fingerprint)),
        ];
        foreach ($attempts as $name => $attempt) {
            try {
                $attempt();
                self::fail($name.' opened the object.');
            } catch (CryptoException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testReadsWhatTheWorkerWrites(): void
    {
        $fixture = self::fixture(self::GO_FIXTURE);
        $object = base64_decode($fixture['object'], true);
        self::assertIsString($object);
        self::assertSame($fixture['version'], BlobFormat::version($object));
        $content = self::join(BlobFormat::decrypt(str_split($object, 1000), static fn (): string => (string) hex2bin($fixture['blobKey']), Uuid::fromString($fixture['owner']), $fixture['fingerprint']));
        self::assertSame(self::content($fixture), $content);
    }

    public function testWritesTheFixtureTheWorkerReads(): void
    {
        if ('1' === getenv('WRITE_BLOB_FIXTURE')) {
            $fixture = ['blobKey' => bin2hex($this->blobKey), 'owner' => $this->owner->toRfc4122(), 'fingerprint' => $this->fingerprint, 'version' => 4, 'pattern' => 'Written by the API. ', 'length' => BlobFormat::FRAME_BYTES + 300, 'object' => ''];
            $fixture['object'] = base64_encode($this->seal(self::content($fixture), 4));
            file_put_contents(self::PHP_FIXTURE, json_encode($fixture, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
        }

        $fixture = self::fixture(self::PHP_FIXTURE);
        $object = base64_decode($fixture['object'], true);
        self::assertIsString($object);
        $content = self::join(BlobFormat::decrypt([$object], static fn (): string => (string) hex2bin($fixture['blobKey']), Uuid::fromString($fixture['owner']), $fixture['fingerprint']));
        self::assertSame(self::content($fixture), $content);
    }

    private function seal(string $content, int $version): string
    {
        $out = fopen('php://memory', 'w+b');
        self::assertIsResource($out);
        BlobFormat::encrypt($this->blobKey, $version, $this->owner, $this->fingerprint, '' === $content ? [] : str_split($content, 10_000), $out);
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * @param iterable<string> $chunks
     */
    private function open(iterable $chunks): string
    {
        return self::join(BlobFormat::decrypt($chunks, fn (): string => $this->blobKey, $this->owner, $this->fingerprint));
    }

    /**
     * @param iterable<string> $chunks
     */
    private static function join(iterable $chunks): string
    {
        $content = '';
        foreach ($chunks as $chunk) {
            $content .= $chunk;
        }

        return $content;
    }

    private static function flip(string $bytes, int $at): string
    {
        $bytes[$at] = \chr(\ord($bytes[$at]) ^ 1);

        return $bytes;
    }

    /**
     * @return array{blobKey: string, owner: string, fingerprint: string, version: int, pattern: string, length: int, object: string}
     */
    private static function fixture(string $path): array
    {
        /** @var array{blobKey: string, owner: string, fingerprint: string, version: int, pattern: string, length: int, object: string} */
        return json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array{pattern: string, length: int} $fixture
     */
    private static function content(array $fixture): string
    {
        return substr(str_repeat($fixture['pattern'], intdiv($fixture['length'], \strlen($fixture['pattern'])) + 1), 0, $fixture['length']);
    }
}
