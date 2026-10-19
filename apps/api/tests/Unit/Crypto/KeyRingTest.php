<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crypto;

use App\Security\Crypto\CryptoException;
use App\Security\Crypto\KeyRing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeyRingTest extends TestCase
{
    public function testParsesKeysAndCurrentKey(): void
    {
        $k1 = random_bytes(32);
        $k2 = random_bytes(32);
        $ring = new KeyRing('k1:'.base64_encode($k1).', k2:'.base64_encode($k2), 'k2');

        self::assertSame('k2', $ring->currentId());
        self::assertSame($k1, $ring->get('k1'));
        self::assertSame($k2, $ring->get('k2'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'current key missing' => ['k1:'.base64_encode(str_repeat('a', 32)), 'k2'];
        yield 'key too short' => ['k1:'.base64_encode('short'), 'k1'];
        yield 'not base64' => ['k1:***', 'k1'];
        yield 'no id' => [base64_encode(str_repeat('a', 32)), 'k1'];
        yield 'bad id' => ['k 1:'.base64_encode(str_repeat('a', 32)), 'k 1'];
        yield 'empty' => ['', 'k1'];
    }

    #[DataProvider('invalidConfigurations')]
    public function testRejectsInvalidConfiguration(string $keys, string $current): void
    {
        $this->expectException(CryptoException::class);

        new KeyRing($keys, $current);
    }

    public function testUnknownKeyIdThrows(): void
    {
        $ring = new KeyRing('k1:'.base64_encode(random_bytes(32)), 'k1');

        $this->expectException(CryptoException::class);
        $ring->get('k9');
    }
}
