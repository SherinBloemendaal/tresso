<?php

declare(strict_types=1);

namespace App\Notification\Channel;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The server's VAPID key pair (RFC 8292), from the environment as base64url raw values. Without keys Web Push is off:
 * clients get no public key and do not subscribe.
 */
final readonly class VapidKeys
{
    public function __construct(
        #[Autowire('%env(default::WEB_PUSH_PUBLIC_KEY)%')]
        private ?string $publicKey,
        #[Autowire('%env(default::WEB_PUSH_PRIVATE_KEY)%')]
        private ?string $privateKey,
        #[Autowire('%env(default::WEB_PUSH_SUBJECT)%')]
        private ?string $subject,
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->publicKey && '' !== $this->publicKey && null !== $this->privateKey && '' !== $this->privateKey;
    }

    /** The application server key browsers subscribe with (base64url, uncompressed point). */
    public function publicKey(): ?string
    {
        return $this->isConfigured() ? $this->publicKey : null;
    }

    /** The Authorization header for one push service. */
    public function authorization(string $endpoint, \DateTimeImmutable $now): string
    {
        if (!$this->isConfigured()) {
            throw new \LogicException('Web Push has no VAPID keys (WEB_PUSH_PUBLIC_KEY, WEB_PUSH_PRIVATE_KEY).');
        }
        $parts = parse_url($endpoint);
        $audience = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        $pem = WebPushEncryption::privateKeyPem(
            WebPushEncryption::base64UrlDecode((string) $this->privateKey),
            WebPushEncryption::base64UrlDecode((string) $this->publicKey),
        );
        if ('' === $pem) {
            throw new \LogicException('Empty VAPID key.');
        }
        $configuration = Configuration::forAsymmetricSigner(new Sha256(), InMemory::plainText($pem), InMemory::plainText($pem));
        $token = $configuration->builder()
            ->permittedFor($audience)
            ->expiresAt($now->modify('+12 hours'))
            ->relatedTo(null !== $this->subject && '' !== $this->subject ? $this->subject : 'mailto:postmaster@localhost')
            ->getToken($configuration->signer(), $configuration->signingKey());

        return \sprintf('vapid t=%s, k=%s', $token->toString(), (string) $this->publicKey);
    }

    /**
     * A new key pair, for app:notifications:vapid-keys.
     *
     * @return array{public: string, private: string}
     */
    public static function generate(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
        $details = false === $key ? false : openssl_pkey_get_details($key);
        $ec = \is_array($details) ? ($details['ec'] ?? null) : null;
        if (false === $key || !\is_array($ec) || !\is_string($ec['d'] ?? null)) {
            throw new \RuntimeException('Could not create a P-256 key: '.openssl_error_string());
        }

        return [
            'public' => WebPushEncryption::base64UrlEncode(WebPushEncryption::publicPoint($key)),
            'private' => WebPushEncryption::base64UrlEncode(str_pad($ec['d'], 32, "\x00", \STR_PAD_LEFT)),
        ];
    }
}
