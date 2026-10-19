<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crypto;

use App\Entity\MailAccount;
use App\Entity\ProviderGrant;
use App\Entity\User;
use App\Enum\MailProvider;
use App\Enum\OAuthProvider;
use App\Security\Crypto\CredentialVault;
use App\Security\Crypto\CryptoException;
use App\Security\Crypto\DataKeyManager;
use App\Security\Crypto\KeyRing;
use PHPUnit\Framework\TestCase;

final class CredentialVaultTest extends TestCase
{
    private CredentialVault $vault;
    private User $owner;

    protected function setUp(): void
    {
        $this->vault = new CredentialVault(new DataKeyManager(new KeyRing('k1:'.base64_encode(random_bytes(32)), 'k1')));
        $this->owner = new User('kim@example.test', 'Kim');
    }

    public function testSealsAndOpensCredentials(): void
    {
        $account = $this->account('work@example.test');

        $this->vault->seal($account, ['type' => 'password', 'password' => 'p@ss w0rd']);

        self::assertStringStartsWith('v1.', (string) $account->getEncryptedCredentials());
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
}
