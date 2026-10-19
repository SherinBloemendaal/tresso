<?php

declare(strict_types=1);

namespace App\Realtime;

use Lcobucci\JWT\JwtFacade;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Builder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Access tokens for the Mercure hub in the format of the current specification: JWT access tokens (typ at+jwt,
 * RFC 9068) whose grants are RFC 9396 authorization details. The hub trusts issuer "tresso-api" and expects the hub's
 * public URL as audience.
 */
final readonly class MercureTokens
{
    public const string ISSUER = 'tresso-api';
    public const string AUTHORIZATION_DETAIL_TYPE = 'https://mercure.rocks/authorization-detail';
    /** Where the sync workers hear about accounts with mail to send. */
    public const string OUTBOX_TOPIC = 'urn:tresso:sync:outbox';
    private const string SUBSCRIBER_TTL = 'PT1H';
    private const string PUBLISHER_TTL = 'PT5M';

    /** HS256 keys shorter than the hash output weaken the signature; lcobucci/jwt refuses them as well. */
    private const int MIN_KEY_BYTES = 32;

    /** @var non-empty-string */
    private string $hubUrl;

    /** @var non-empty-string */
    private string $publisherKey;

    /** @var non-empty-string */
    private string $subscriberKey;

    public function __construct(
        #[Autowire(env: 'MERCURE_PUBLIC_URL')]
        string $hubUrl,
        #[Autowire(env: 'MERCURE_PUBLISHER_JWT_KEY')]
        #[\SensitiveParameter]
        string $publisherKey,
        #[Autowire(env: 'MERCURE_SUBSCRIBER_JWT_KEY')]
        #[\SensitiveParameter]
        string $subscriberKey,
    ) {
        if (false === filter_var($hubUrl, \FILTER_VALIDATE_URL) || '' === $hubUrl) {
            throw new \InvalidArgumentException('MERCURE_PUBLIC_URL must be an absolute URL.');
        }
        if (\strlen($publisherKey) < self::MIN_KEY_BYTES) {
            throw new \InvalidArgumentException(\sprintf('MERCURE_PUBLISHER_JWT_KEY must be at least %d bytes.', self::MIN_KEY_BYTES));
        }
        if (\strlen($subscriberKey) < self::MIN_KEY_BYTES) {
            throw new \InvalidArgumentException(\sprintf('MERCURE_SUBSCRIBER_JWT_KEY must be at least %d bytes.', self::MIN_KEY_BYTES));
        }
        if ($publisherKey === $subscriberKey) {
            throw new \InvalidArgumentException('MERCURE_PUBLISHER_JWT_KEY and MERCURE_SUBSCRIBER_JWT_KEY must differ, or subscribers could publish.');
        }
        $this->hubUrl = $hubUrl;
        $this->publisherKey = $publisherKey;
        $this->subscriberKey = $subscriberKey;
    }

    /** @return non-empty-string */
    public function hubUrl(): string
    {
        return $this->hubUrl;
    }

    /** The private topic a user's clients listen on for "new changes" notifications. */
    public static function changesTopic(Uuid $userId): string
    {
        return 'urn:tresso:user:'.$userId->toRfc4122().':changes';
    }

    /** For a sync worker: subscribe to the outbox topic. */
    public function workerToken(string $clientId): Token
    {
        if ('' === $clientId) {
            throw new \InvalidArgumentException('A worker token needs the OAuth client it is issued to.');
        }

        return $this->issue($this->subscriberKey, $clientId, $clientId, ['subscribe'], [self::OUTBOX_TOPIC], self::SUBSCRIBER_TTL);
    }

    public function subscriberToken(Uuid $userId, string $clientId): Token
    {
        if ('' === $clientId) {
            throw new \InvalidArgumentException('A subscriber token needs the OAuth client it is issued to.');
        }

        return $this->issue($this->subscriberKey, $userId->toRfc4122(), $clientId, ['subscribe'], [self::changesTopic($userId)], self::SUBSCRIBER_TTL);
    }

    /**
     * @param list<string> $topics
     */
    public function publisherToken(array $topics): Token
    {
        return $this->issue($this->publisherKey, self::ISSUER, self::ISSUER, ['publish'], $topics, self::PUBLISHER_TTL);
    }

    /**
     * @param non-empty-string $key
     * @param non-empty-string $subject
     * @param list<string>     $actions
     * @param list<string>     $topics
     */
    private function issue(string $key, string $subject, string $clientId, array $actions, array $topics, string $ttl): Token
    {
        $expiresAt = null;
        $jwt = new JwtFacade()->issue(
            new Sha256(),
            InMemory::plainText($key),
            function (Builder $builder, \DateTimeImmutable $now) use ($subject, $clientId, $actions, $topics, $ttl, &$expiresAt): Builder {
                // JWT times are whole seconds; the reported expiry must not outlive the token's own "exp".
                $now = $now->setTime((int) $now->format('G'), (int) $now->format('i'), (int) $now->format('s'));
                $expiresAt = $now->add(new \DateInterval($ttl));

                return $builder
                    ->withHeader('typ', 'at+jwt')
                    ->issuedBy(self::ISSUER)
                    ->permittedFor($this->hubUrl)
                    ->relatedTo($subject)
                    ->identifiedBy(Uuid::v7()->toRfc4122())
                    ->issuedAt($now)
                    ->expiresAt($expiresAt)
                    ->withClaim('client_id', $clientId)
                    ->withClaim('authorization_details', [[
                        'type' => self::AUTHORIZATION_DETAIL_TYPE,
                        'actions' => $actions,
                        'topics' => array_map(static fn (string $topic): array => ['match' => $topic, 'match_type' => 'exact'], $topics),
                    ]]);
            },
        );

        \assert($expiresAt instanceof \DateTimeImmutable);

        return new Token($jwt->toString(), $expiresAt);
    }
}
