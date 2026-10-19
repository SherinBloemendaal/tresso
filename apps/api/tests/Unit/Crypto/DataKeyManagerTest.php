<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crypto;

use App\Entity\User;
use App\Security\Crypto\CryptoException;
use App\Security\Crypto\DataKeyManager;
use App\Security\Crypto\KeyRing;
use PHPUnit\Framework\TestCase;

final class DataKeyManagerTest extends TestCase
{
    private string $oldKey;
    private string $newKey;

    protected function setUp(): void
    {
        $this->oldKey = base64_encode(random_bytes(32));
        $this->newKey = base64_encode(random_bytes(32));
    }

    public function testCreatesDataKeyOnceAndReturnsTheSameKeyAfterwards(): void
    {
        $manager = new DataKeyManager($this->ring('old'));
        $user = new User('kim@example.test', 'Kim');

        $first = $manager->dataKeyFor($user);
        $wrapped = $user->getWrappedDataKey();

        self::assertSame(32, \strlen($first));
        self::assertIsString($wrapped);
        self::assertStringStartsWith('v1.old.', $wrapped);
        self::assertStringNotContainsString(base64_encode($first), $wrapped);
        self::assertSame($first, $manager->dataKeyFor($user));
        self::assertSame($wrapped, $user->getWrappedDataKey(), 'An existing data key is never replaced.');
    }

    public function testRewrapMovesToCurrentMasterKeyAndKeepsTheDataKey(): void
    {
        $user = new User('kim@example.test', 'Kim');
        $dataKey = new DataKeyManager($this->ring('old'))->dataKeyFor($user);

        $rotating = new DataKeyManager($this->ring('new'));
        self::assertTrue($rotating->rewrap($user));
        self::assertStringStartsWith('v1.new.', (string) $user->getWrappedDataKey());
        self::assertFalse($rotating->rewrap($user), 'Already on the current key.');
        self::assertSame($dataKey, $rotating->dataKeyFor($user));

        $withoutOldKey = new DataKeyManager(new KeyRing('new:'.$this->newKey, 'new'));
        self::assertSame($dataKey, $withoutOldKey->dataKeyFor($user), 'After rotation the old key can be removed.');
    }

    public function testWrappedKeyIsBoundToItsUser(): void
    {
        $manager = new DataKeyManager($this->ring('old'));
        $kim = new User('kim@example.test', 'Kim');
        $manager->dataKeyFor($kim);

        $lisa = new User('lisa@example.test', 'Lisa');
        $lisa->setWrappedDataKey((string) $kim->getWrappedDataKey());

        $this->expectException(CryptoException::class);
        $manager->dataKeyFor($lisa);
    }

    public function testTamperedWrappedKeyIsRejected(): void
    {
        $manager = new DataKeyManager($this->ring('old'));
        $user = new User('kim@example.test', 'Kim');
        $manager->dataKeyFor($user);

        $wrapped = (string) $user->getWrappedDataKey();
        $payload = (string) base64_decode(substr($wrapped, \strlen('v1.old.')), true);
        $payload[30] = \chr(\ord($payload[30]) ^ 1);
        $user->setWrappedDataKey('v1.old.'.base64_encode($payload));

        $this->expectException(CryptoException::class);
        $manager->dataKeyFor($user);
    }

    public function testUnknownFormatIsRejected(): void
    {
        $user = new User('kim@example.test', 'Kim');
        $user->setWrappedDataKey('v0.old.abc');

        $this->expectException(CryptoException::class);
        new DataKeyManager($this->ring('old'))->dataKeyFor($user);
    }

    private function ring(string $current): KeyRing
    {
        return new KeyRing('old:'.$this->oldKey.',new:'.$this->newKey, $current);
    }
}
