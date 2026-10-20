<?php

declare(strict_types=1);

namespace App\Security\Crypto;

use App\Entity\MailAccount;
use App\Entity\ProviderGrant;
use App\Entity\User;

/**
 * Encrypts mail account passwords and provider grant tokens with the owner's field key, bound to the record they
 * belong to (a sealed value copied to another record does not open). The plaintext is a JSON object.
 *
 * Sealed with {@see FieldVault} ("f1.<key version>.<...>"). Values from before user keys, "v1.<base64(nonce +
 * ciphertext)>" sealed with the earlier data key itself, still open: that key is key version 1.
 */
final readonly class CredentialVault
{
    private const string LEGACY = 'v1';

    public function __construct(
        private FieldVault $fields,
        private UserKeys $keys,
    ) {
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

    /** Whether the account's credentials are sealed with an older key, or in the format from before user keys. */
    public function needsReseal(MailAccount $account): bool
    {
        $sealed = $account->getEncryptedCredentials();

        return null !== $sealed && $this->isOutdated($account->getOwner(), $sealed);
    }

    public function grantNeedsReseal(ProviderGrant $grant): bool
    {
        return $this->isOutdated($grant->getOwner(), $grant->getEncryptedTokens());
    }

    private function isOutdated(User $owner, string $sealed): bool
    {
        return !FieldVault::isSealed($sealed) || FieldVault::versionOf($sealed) !== $this->keys->current($owner->getId())->version;
    }

    /**
     * @param array<string, scalar|null> $secrets
     */
    private function sealFor(User $owner, string $context, #[\SensitiveParameter] array $secrets): string
    {
        $plaintext = json_encode($secrets, \JSON_THROW_ON_ERROR);
        $sealed = $this->fields->seal($owner->getId(), $context, $plaintext);
        sodium_memzero($plaintext);

        return $sealed;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function openFor(User $owner, string $context, string $sealed): array
    {
        $plaintext = FieldVault::isSealed($sealed) ? $this->fields->open($owner->getId(), $context, $sealed) : $this->openLegacy($owner, $context, $sealed);

        /** @var array<string, scalar|null> $secrets */
        $secrets = json_decode($plaintext, true, flags: \JSON_THROW_ON_ERROR);
        sodium_memzero($plaintext);

        return $secrets;
    }

    private function openLegacy(User $owner, string $context, string $sealed): string
    {
        $parts = explode('.', $sealed, 2);
        $payload = 2 === \count($parts) && self::LEGACY === $parts[0] ? base64_decode($parts[1], true) : false;
        if (false === $payload) {
            throw new CryptoException('Unsupported credential format.');
        }

        return Aead::decrypt($payload, $context, $this->keys->version($owner->getId(), 1));
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
