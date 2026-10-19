<?php

declare(strict_types=1);

namespace App\Tests\Unit\Realtime;

use App\Realtime\MercureTokens;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class MercureTokensTest extends TestCase
{
    private const string HUB = 'https://app.example.test/.well-known/mercure';
    private const string PUBLISHER_KEY = 'publisher-key-0123456789-0123456789-0123';
    private const string SUBSCRIBER_KEY = 'subscriber-key-0123456789-0123456789-012';

    private MercureTokens $tokens;

    protected function setUp(): void
    {
        $this->tokens = new MercureTokens(self::HUB, self::PUBLISHER_KEY, self::SUBSCRIBER_KEY);
    }

    public function testSubscriberTokenGrantsOnlyTheUsersOwnTopic(): void
    {
        $userId = Uuid::v7();

        $token = $this->tokens->subscriberToken($userId, 'web');
        $jwt = $this->parse($token->value);

        self::assertSame('at+jwt', $jwt->headers()->get('typ'));
        self::assertSame('HS256', $jwt->headers()->get('alg'));
        self::assertSame(MercureTokens::ISSUER, $jwt->claims()->get('iss'));
        self::assertSame([self::HUB], $jwt->claims()->get('aud'));
        self::assertSame($userId->toRfc4122(), $jwt->claims()->get('sub'));
        self::assertSame('web', $jwt->claims()->get('client_id'));
        self::assertIsString($jwt->claims()->get('jti'));
        self::assertSame([[
            'type' => 'https://mercure.rocks/authorization-detail',
            'actions' => ['subscribe'],
            'topics' => [['match' => 'urn:tresso:user:'.$userId->toRfc4122().':changes', 'match_type' => 'exact']],
        ]], $jwt->claims()->get('authorization_details'));
    }

    public function testSubscriberTokenIsSignedWithTheSubscriberKeyOnly(): void
    {
        $jwt = $this->parse($this->tokens->subscriberToken(Uuid::v7(), 'web')->value);

        self::assertTrue(new Validator()->validate($jwt, new SignedWith(new Sha256(), InMemory::plainText(self::SUBSCRIBER_KEY))));
        self::assertFalse(new Validator()->validate($jwt, new SignedWith(new Sha256(), InMemory::plainText(self::PUBLISHER_KEY))));
    }

    public function testSubscriberTokenLivesOneHour(): void
    {
        $token = $this->tokens->subscriberToken(Uuid::v7(), 'web');
        $jwt = $this->parse($token->value);

        $issuedAt = $jwt->claims()->get('iat');
        self::assertInstanceOf(\DateTimeImmutable::class, $issuedAt);
        self::assertEquals($issuedAt->modify('+1 hour'), $token->expiresAt);
        self::assertEquals($token->expiresAt, $jwt->claims()->get('exp'));
    }

    public function testPublisherTokenGrantsPublishingToTheGivenTopics(): void
    {
        $jwt = $this->parse($this->tokens->publisherToken(['urn:tresso:user:a:changes', 'urn:tresso:user:b:changes'])->value);

        self::assertTrue(new Validator()->validate($jwt, new SignedWith(new Sha256(), InMemory::plainText(self::PUBLISHER_KEY))));
        self::assertSame(MercureTokens::ISSUER, $jwt->claims()->get('sub'));
        self::assertSame([[
            'type' => 'https://mercure.rocks/authorization-detail',
            'actions' => ['publish'],
            'topics' => [
                ['match' => 'urn:tresso:user:a:changes', 'match_type' => 'exact'],
                ['match' => 'urn:tresso:user:b:changes', 'match_type' => 'exact'],
            ],
        ]], $jwt->claims()->get('authorization_details'));
    }

    public function testTokensAreUnique(): void
    {
        $userId = Uuid::v7();

        self::assertNotSame(
            $this->parse($this->tokens->subscriberToken($userId, 'web')->value)->claims()->get('jti'),
            $this->parse($this->tokens->subscriberToken($userId, 'web')->value)->claims()->get('jti'),
        );
    }

    private function parse(string $token): Plain
    {
        if ('' === $token) {
            self::fail('Empty token.');
        }
        $jwt = new Parser(new JoseEncoder())->parse($token);
        self::assertInstanceOf(Plain::class, $jwt);

        return $jwt;
    }
}
