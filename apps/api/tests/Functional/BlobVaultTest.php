<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Security\Crypto\CryptoException;
use App\Storage\BlobKey;
use App\Storage\BlobKind;
use App\Storage\BlobVault;
use App\Tests\Support\InMemoryObjectStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class BlobVaultTest extends DatabaseTestCase
{
    private Uuid $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $user = new User('kim@example.test', 'Kim');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();
        $this->owner = $user->getId();
    }

    protected function tearDown(): void
    {
        $this->storage()->whileWriting = null;
        parent::tearDown();
    }

    public function testReencryptingNeverPutsPlaintextInATemporaryFile(): void
    {
        $marker = 'plaintext-marker-'.bin2hex(random_bytes(8));
        // Larger than what a temporary stream keeps in memory (2 MiB), so a buffered copy would be on disk.
        $content = str_repeat($marker.' ', 300_000);
        $legacy = BlobKey::legacy(BlobKind::Raw, hash('sha256', $content));
        $this->storage()->put($legacy, $content);
        $onDisk = [];
        $this->storage()->whileWriting = static function () use ($marker, &$onDisk): void {
            foreach (glob(sys_get_temp_dir().'/php*') ?: [] as $file) {
                if (is_file($file) && str_contains((string) file_get_contents($file), $marker)) {
                    $onDisk[] = $file;
                }
            }
        };

        $stored = $this->vault()->reencrypt($this->owner, BlobKind::Raw, $legacy);

        self::assertSame([], $onDisk, 'No temporary file held the plaintext while it was encrypted.');
        self::assertSame($content, $this->vault()->read($this->owner, $stored->key));
    }

    public function testContentFromBeforeThatDoesNotMatchItsNameIsNotMoved(): void
    {
        $legacy = BlobKey::legacy(BlobKind::Attachments, hash('sha256', 'the invoice'));
        $this->storage()->put($legacy, 'something else');

        try {
            $this->vault()->reencrypt($this->owner, BlobKind::Attachments, $legacy);
            self::fail('Re-encrypted content that is not what its name says.');
        } catch (CryptoException) {
        }

        self::assertSame([$legacy], $this->storage()->keys(), 'Nothing was written.');
    }

    public function testReencryptingKeepsTheNameAndTheContent(): void
    {
        $stored = $this->vault()->store($this->owner, BlobKind::Uploads, 'notes');

        $again = $this->vault()->reencrypt($this->owner, BlobKind::Uploads, $stored->key);

        self::assertSame([$stored->key, $stored->fingerprint, 5], [$again->key, $again->fingerprint, $again->size]);
        self::assertSame('notes', $this->vault()->read($this->owner, $again->key));
    }

    private function vault(): BlobVault
    {
        return static::getContainer()->get(BlobVault::class);
    }

    private function storage(): InMemoryObjectStorage
    {
        return static::getContainer()->get(InMemoryObjectStorage::class);
    }
}
