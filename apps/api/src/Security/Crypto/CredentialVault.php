<?php

declare(strict_types=1);

namespace App\Security\Crypto;

use App\Entity\MailAccount;

/**
 * Encrypts mail account credentials with the owner's data key, bound to the account id.
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
        $key = $this->dataKeys->dataKeyFor($account->getOwner());
        $plaintext = json_encode($credentials, \JSON_THROW_ON_ERROR);
        $account->setEncryptedCredentials(self::VERSION.'.'.base64_encode(Aead::encrypt($plaintext, $this->additionalData($account), $key)));
        sodium_memzero($key);
        sodium_memzero($plaintext);
    }

    /**
     * @return array<string, scalar|null>
     */
    public function open(MailAccount $account): array
    {
        $sealed = $account->getEncryptedCredentials() ?? throw new CryptoException('The mail account has no credentials.');
        $parts = explode('.', $sealed, 2);
        $payload = 2 === \count($parts) && self::VERSION === $parts[0] ? base64_decode($parts[1], true) : false;
        if (false === $payload) {
            throw new CryptoException('Unsupported credential format.');
        }

        $key = $this->dataKeys->dataKeyFor($account->getOwner());
        $plaintext = Aead::decrypt($payload, $this->additionalData($account), $key);
        sodium_memzero($key);

        /** @var array<string, scalar|null> $credentials */
        $credentials = json_decode($plaintext, true, flags: \JSON_THROW_ON_ERROR);
        sodium_memzero($plaintext);

        return $credentials;
    }

    private function additionalData(MailAccount $account): string
    {
        return 'tresso:mail-account-credentials:'.$account->getId()->toRfc4122();
    }
}
