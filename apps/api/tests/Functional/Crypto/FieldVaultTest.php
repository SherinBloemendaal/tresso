<?php

declare(strict_types=1);

namespace App\Tests\Functional\Crypto;

use App\Entity\User;
use App\Security\Crypto\CryptoException;
use App\Security\Crypto\FieldVault;
use App\Security\Crypto\UserKeys;
use App\Tests\Functional\DatabaseTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class FieldVaultTest extends DatabaseTestCase
{
    public function testSealsWithTheCurrentVersionAndOpensOlderOnes(): void
    {
        $kim = $this->user('kim@example.test');
        $old = $this->vault()->seal($kim, 'tresso:test:1', 'first');
        static::getContainer()->get(UserKeys::class)->rotate($kim, new \DateTimeImmutable());
        static::getContainer()->get(UserKeys::class)->reset();

        $new = $this->vault()->seal($kim, 'tresso:test:1', 'second');

        self::assertSame(1, FieldVault::versionOf($old));
        self::assertSame(2, FieldVault::versionOf($new));
        self::assertSame('first', $this->vault()->open($kim, 'tresso:test:1', $old));
        self::assertSame('second', $this->vault()->open($kim, 'tresso:test:1', $new));
    }

    public function testSealsAnEmptyValue(): void
    {
        $kim = $this->user('kim@example.test');

        self::assertSame('', $this->vault()->open($kim, 'tresso:test', $this->vault()->seal($kim, 'tresso:test', '')));
    }

    public function testASealedValueOpensOnlyForItsOwnerAndContext(): void
    {
        $kim = $this->user('kim@example.test');
        $lisa = $this->user('lisa@example.test');
        $sealed = $this->vault()->seal($kim, 'tresso:message-snippet:a', 'secret');

        foreach ([[$lisa, 'tresso:message-snippet:a'], [$kim, 'tresso:message-snippet:b']] as [$owner, $context]) {
            try {
                $this->vault()->open($owner, $context, $sealed);
                self::fail('Opened for another owner or context.');
            } catch (CryptoException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTamperingIsDetected(): void
    {
        $kim = $this->user('kim@example.test');
        $sealed = $this->vault()->seal($kim, 'tresso:test', 'secret');
        $payload = (string) base64_decode(explode('.', $sealed, 3)[2], true);
        $payload[30] = \chr(\ord($payload[30]) ^ 1);

        $this->expectException(CryptoException::class);
        $this->vault()->open($kim, 'tresso:test', 'f1.1.'.base64_encode($payload));
    }

    public function testPlaintextIsNotMistakenForASealedValue(): void
    {
        self::assertFalse(FieldVault::isSealed('f1. Formula one results'));
        self::assertTrue(FieldVault::isSealed('f1.12.AAAA'));
    }

    private function vault(): FieldVault
    {
        return static::getContainer()->get(FieldVault::class);
    }

    private function user(string $email): Uuid
    {
        $user = new User($email, 'User');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user->getId();
    }
}
