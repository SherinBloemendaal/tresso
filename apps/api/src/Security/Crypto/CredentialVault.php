<?php

declare(strict_types=1);

namespace App\Security\Crypto;

use App\Entity\MailAccount;
use App\Entity\ProviderGrant;
use App\Entity\User;

/**
 * Encrypts mail account passwords and provider grant tokens with the owner's data key, bound to the record they
 * belong to (a sealed value copied to another record does not open).
 *
 * Sealed format: "v1.<base64(nonce + ciphertext)>"; the plaintext is a JSON object.
 */
final readonly class CredentialVault
{
    private const string VERSION = 'v1';

    public function __construct(private DataKeyManager $dataKeys)
    {
    }

    /**
     * @param array<string, scalar|null> $credentials
     */
    public function seal(MailAccount $account, #[\SensitiveParameter] array $credentials): void
    {
        $account->setEncryptedCredentials($this->sealFor($account->getOwner(), self::accountContext($account), $credentials));
    }

    /**
     * @return array<string, scalar|null>
     */
    public function open(MailAccount $account): array
    {
        $sealed = $account->getEncryptedCredentials() ?? throw new CryptoException('The mail account has no credentials.');

        return $this->openFor($account->getOwner(), self::accountContext($account), $sealed);
    }

    /**
     * @param array<string, scalar|null> $tokens
     */
    public function sealGrant(ProviderGrant $grant, #[\SensitiveParameter] array $tokens): void
    {
        $grant->setEncryptedTokens($this->sealFor($grant->getOwner(), self::grantContext($grant), $tokens));
    }

    /**
     * @return array<string, scalar|null>
     */
    public function openGrant(ProviderGrant $grant): array
    {
        return $this->openFor($grant->getOwner(), self::grantContext($grant), $grant->getEncryptedTokens());
    }

    /**
     * @param array<string, scalar|null> $secrets
     */
    private function sealFor(User $owner, string $context, #[\SensitiveParameter] array $secrets): string
    {
        $key = $this->dataKeys->dataKeyFor($owner);
        $plaintext = json_encode($secrets, \JSON_THROW_ON_ERROR);
        $sealed = self::VERSION.'.'.base64_encode(Aead::encrypt($plaintext, $context, $key));
        sodium_memzero($key);
        sodium_memzero($plaintext);

        return $sealed;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function openFor(User $owner, string $context, string $sealed): array
    {
        $parts = explode('.', $sealed, 2);
        $payload = 2 === \count($parts) && self::VERSION === $parts[0] ? base64_decode($parts[1], true) : false;
        if (false === $payload) {
            throw new CryptoException('Unsupported credential format.');
        }

        $key = $this->dataKeys->dataKeyFor($owner);
        $plaintext = Aead::decrypt($payload, $context, $key);
        sodium_memzero($key);

        /** @var array<string, scalar|null> $secrets */
        $secrets = json_decode($plaintext, true, flags: \JSON_THROW_ON_ERROR);
        sodium_memzero($plaintext);

        return $secrets;
    }

    private static function accountContext(MailAccount $account): string
    {
        return 'tresso:mail-account-credentials:'.$account->getId()->toRfc4122();
    }

    private static function grantContext(ProviderGrant $grant): string
    {
        return 'tresso:provider-grant-tokens:'.$grant->getId()->toRfc4122();
    }
}
