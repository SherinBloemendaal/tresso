<?php

declare(strict_types=1);

namespace App\Tests\Functional\Crypto;

use App\Entity\MailAccount;
use App\Entity\ProviderGrant;
use App\Entity\User;
use App\Enum\MailProvider;
use App\Enum\OAuthProvider;
use App\Security\Crypto\Aead;
use App\Security\Crypto\CredentialVault;
use App\Security\Crypto\CryptoException;
use App\Security\Crypto\KeyRing;
use App\Security\Crypto\UserKeys;
use App\Tests\Functional\DatabaseTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class CredentialVaultTest extends DatabaseTestCase
{
    private CredentialVault $vault;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vault = static::getContainer()->get(CredentialVault::class);
        $this->owner = $this->user('kim@example.test');
    }

    public function testSealsAndOpensCredentials(): void
    {
        $account = $this->account('work@example.test');

        $this->vault->seal($account, ['type' => 'password', 'password' => 'p@ss w0rd']);

        self::assertStringStartsWith('f1.1.', (string) $account->getEncryptedCredentials());
        self::assertStringNotContainsString('p@ss w0rd', (string) $account->getEncryptedCredentials());
        self::assertSame(['type' => 'password', 'password' => 'p@ss w0rd'], $this->vault->open($account));
    }

    public function testSealingTwiceGivesDifferentCiphertexts(): void
    {
        $account = $this->account('work@example.test');

        $this->vault->seal($account, ['password' => 'same']);
        $first = $account->getEncryptedCredentials();
        $this->vault->seal($account, ['password' => 'same']);

        self::assertNotSame($first, $account->getEncryptedCredentials(), 'Every seal uses a fresh nonce.');
    }

    public function testCiphertextIsBoundToItsAccount(): void
    {
        $work = $this->account('work@example.test');
        $this->vault->seal($work, ['password' => 'secret']);

        $personal = $this->account('personal@example.test');
        $personal->setEncryptedCredentials($work->getEncryptedCredentials());

        $this->expectException(CryptoException::class);
        $this->vault->open($personal);
    }

    public function testCiphertextIsBoundToItsOwner(): void
    {
        $work = $this->account('work@example.test');
        $this->vault->seal($work, ['password' => 'secret']);

        $lisas = new MailAccount($this->user('lisa@example.test'), 'lisa@example.test', 'Account', MailProvider::Imap);
        $lisas->setEncryptedCredentials($work->getEncryptedCredentials());

        $this->expectException(CryptoException::class);
        $this->vault->open($lisas);
    }

    public function testGrantTokensAreSealedAndBoundToTheirGrant(): void
    {
        $grant = new ProviderGrant($this->owner, OAuthProvider::Google, 'sub-1', 'kim@gmail.example');
        $this->vault->sealGrant($grant, ['refreshToken' => 'refresh-1', 'accessToken' => 'access-1']);

        self::assertStringNotContainsString('refresh-1', $grant->getEncryptedTokens());
        self::assertSame(['refreshToken' => 'refresh-1', 'accessToken' => 'access-1'], $this->vault->openGrant($grant));

        $other = new ProviderGrant($this->owner, OAuthProvider::Google, 'sub-2', 'other@gmail.example');
        $other->setEncryptedTokens($grant->getEncryptedTokens());
        $this->expectException(CryptoException::class);
        $this->vault->openGrant($other);
    }

    public function testCredentialsSealedBeforeUserKeysStillOpen(): void
    {
        $user = $this->user('pieter@example.test');
        $dataKey = $this->giveLegacyDataKey($user);
        $account = new MailAccount($user, 'pieter@example.test', 'Account', MailProvider::Imap);
        $account->setEncryptedCredentials('v1.'.base64_encode(Aead::encrypt('{"password":"from before"}', 'tresso:mail-account-credentials:'.$account->getId()->toRfc4122(), $dataKey)));

        self::assertSame(['password' => 'from before'], $this->vault->open($account));
        self::assertTrue($this->vault->needsReseal($account));

        $this->vault->seal($account, $this->vault->open($account));
        self::assertFalse($this->vault->needsReseal($account));
        self::assertSame(['password' => 'from before'], $this->vault->open($account));
    }

    public function testAccountWithoutCredentialsThrows(): void
    {
        $this->expectException(CryptoException::class);

        $this->vault->open($this->account('work@example.test'));
    }

    public function testUnknownFormatThrows(): void
    {
        $account = $this->account('work@example.test');
        $account->setEncryptedCredentials('v9.abc');

        $this->expectException(CryptoException::class);
        $this->vault->open($account);
    }

    private function account(string $email): MailAccount
    {
        return new MailAccount($this->owner, $email, 'Account', MailProvider::Imap);
    }

    private function user(string $email): User
    {
        $user = new User($email, 'User');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    /** A data key as user records had one before user keys. */
    private function giveLegacyDataKey(User $user): string
    {
        $dataKey = random_bytes(32);
        $wrapped = static::getContainer()->get(KeyRing::class)->wrap($dataKey, 'tresso:user-data-key:'.$user->getId()->toRfc4122());
        $this->connection()->executeStatement('UPDATE app_user SET wrapped_data_key = :wrapped WHERE id = :id', ['wrapped' => $wrapped, 'id' => $user->getId()->toRfc4122()]);
        static::getContainer()->get(UserKeys::class)->reset();

        return $dataKey;
    }
}
