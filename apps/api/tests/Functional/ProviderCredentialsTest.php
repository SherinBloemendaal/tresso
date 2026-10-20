<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\MailAccount;
use App\Entity\ProviderGrant;

/**
 * Sync workers get provider access tokens for Gmail and Microsoft 365 accounts through the credentials endpoint; the
 * API refreshes them and keeps the refresh token to itself.
 */
final class ProviderCredentialsTest extends DatabaseTestCase
{
    use AuthenticatesUsers;
    use ConnectsProviderAccounts;

    /** @var array<string, string> */
    private array $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapOAuthClients();
        $this->createUser('kim@example.test', 'Kim Visser');
        $this->user = $this->userHeaders('kim@example.test', ['settings:read', 'settings:write']);
        $this->worker = $this->workerHeaders('sync:lease sync:credentials');
    }

    public function testWorkerGetsTheAccessTokenWithoutTheRefreshToken(): void
    {
        $account = $this->connectGoogle();
        $this->lease();

        $credentials = $this->credentials($account);

        self::assertSame([
            'accountId' => $account->getId()->toRfc4122(),
            'emailAddress' => 'kim@gmail.example',
            'provider' => 'gmail',
            'connection' => null,
            'credentials' => ['type' => 'oauth2', 'accessToken' => 'google-access-1', 'expiresAt' => self::secrets($credentials)['expiresAt'] ?? null],
        ], $credentials);
        $expiresAt = self::secrets($credentials)['expiresAt'] ?? null;
        self::assertIsString($expiresAt);
        $expiresAt = new \DateTimeImmutable($expiresAt);
        self::assertEqualsWithDelta(time() + 3599, $expiresAt->getTimestamp(), 5);
        self::assertCount(1, $this->router()->requestsTo(self::GOOGLE_TOKEN), 'A valid token is not refreshed.');
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
    }

    public function testATokenAboutToExpireIsRefreshed(): void
    {
        $account = $this->connectGoogle();
        $this->sealTokens($account, accessTokenExpiresAt: time() + 120);
        $this->lease();
        $this->router()->respondOnce(self::GOOGLE_TOKEN, (string) json_encode(['access_token' => 'google-access-2', 'expires_in' => 3599, 'scope' => 'openid '.self::GMAIL_SCOPE, 'token_type' => 'Bearer']));

        self::assertSame('google-access-2', $this->accessToken($account));

        $refresh = $this->router()->requestsTo(self::GOOGLE_TOKEN)[1] ?? null;
        self::assertNotNull($refresh);
        parse_str($refresh['body'], $body);
        self::assertSame(['grant_type' => 'refresh_token', 'refresh_token' => 'google-refresh-1', 'client_id' => 'test-google-client', 'client_secret' => 'test-google-secret'], $body);
        self::assertSame('google-access-2', $this->accessToken($account), 'The new token is stored.');
        self::assertCount(2, $this->router()->requestsTo(self::GOOGLE_TOKEN));
    }

    public function testARejectedTokenIsReplaced(): void
    {
        $account = $this->connectGoogle();
        $this->sealTokens($account, accessTokenIssuedAt: time() - 600);
        $this->lease();
        $this->router()->respondOnce(self::GOOGLE_TOKEN, (string) json_encode(['access_token' => 'google-access-2', 'expires_in' => 3599, 'scope' => 'openid '.self::GMAIL_SCOPE]));

        self::assertSame('google-access-1', $this->accessToken($account));
        self::assertSame('google-access-2', $this->accessToken($account, rejected: true));
        self::assertSame('google-access-2', $this->accessToken($account, rejected: true), 'A token issued moments ago is not replaced again.');
        self::assertCount(2, $this->router()->requestsTo(self::GOOGLE_TOKEN));
    }

    public function testMicrosoftReplacesTheRefreshTokenOnEveryRefresh(): void
    {
        $account = $this->connectMicrosoft();
        $this->sealTokens($account, accessTokenExpiresAt: time() + 60);
        $this->lease();
        $this->router()->respondOnce(self::MICROSOFT_TOKEN, (string) json_encode(['access_token' => 'ms-access-2', 'expires_in' => 3600, 'refresh_token' => 'ms-refresh-2', 'scope' => 'https://graph.microsoft.com/Mail.ReadWrite https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read']));

        self::assertSame('ms-access-2', $this->accessToken($account));

        parse_str($this->router()->requestsTo(self::MICROSOFT_TOKEN)[1]['body'] ?? '', $body);
        self::assertSame('ms-refresh-1', $body['refresh_token'] ?? null);
        self::assertSame('openid email profile offline_access https://graph.microsoft.com/Mail.ReadWrite https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read', $body['scope'] ?? null);
        $this->entityManager()->clear();
        $grant = $this->onlyAccount()->getProviderGrant();
        self::assertInstanceOf(ProviderGrant::class, $grant);
        self::assertSame('ms-refresh-2', $this->vault()->openGrant($grant)['refreshToken'] ?? null);
    }

    public function testARefusedGrantStopsTheAccountUntilTheUserSignsInAgain(): void
    {
        $account = $this->connectGoogle();
        $this->sealTokens($account, accessTokenExpiresAt: time() + 60);
        $this->lease();
        $this->router()->respondOnce(self::GOOGLE_TOKEN, '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}', 400);

        $this->client->request('GET', $this->credentialsUrl($account), server: $this->worker);

        self::assertResponseStatusCodeSame(409);
        $this->entityManager()->clear();
        $account = $this->onlyAccount();
        self::assertSame('auth_failed', $account->getStatus()->value);
        self::assertSame('Google no longer lets Tresso use this account. Sign in with Google again.', $account->getStatusDetail());
        self::assertTrue($account->getProviderGrant()?->isRevoked());

        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => 'w1'], $this->worker);
        self::assertSame([], $this->decodedResponse()['accounts'] ?? null, 'A failed account is no longer leased.');
    }

    public function testMicrosoftAskingForTheUserNeedsANewSignIn(): void
    {
        $account = $this->connectMicrosoft();
        $this->sealTokens($account, accessTokenExpiresAt: time() + 60);
        $this->lease();
        $this->router()->respondOnce(self::MICROSOFT_TOKEN, '{"error":"interaction_required","error_description":"AADSTS50076: multi-factor authentication is required."}', 400);

        $this->client->request('GET', $this->credentialsUrl($account), server: $this->worker);

        self::assertResponseStatusCodeSame(409);
        $this->entityManager()->clear();
        self::assertSame('auth_failed', $this->onlyAccount()->getStatus()->value);
    }

    public function testARevokedGrantIsRefusedWithoutAskingTheProvider(): void
    {
        $account = $this->connectGoogle();
        $this->lease();
        $this->connection()->executeStatement('UPDATE provider_grant SET revoked_at = now()');

        $this->client->request('GET', $this->credentialsUrl($account), server: $this->worker);

        self::assertResponseStatusCodeSame(409);
        self::assertCount(1, $this->router()->requestsTo(self::GOOGLE_TOKEN));
    }

    public function testAGrantWithoutMailScopesNeedsANewSignIn(): void
    {
        $account = $this->connectGoogle();
        $this->lease();
        $this->connection()->executeStatement("UPDATE provider_grant SET scopes = '[\"openid\"]'");

        $this->client->request('GET', $this->credentialsUrl($account), server: $this->worker);

        self::assertResponseStatusCodeSame(409);
        $this->entityManager()->clear();
        self::assertSame('auth_failed', $this->onlyAccount()->getStatus()->value);
    }

    public function testCalendarTokensWaitForTheUserToAllowCalendarsWhileMailGoesOn(): void
    {
        $account = $this->connectGoogle();
        $this->lease();

        $this->client->request('GET', $this->credentialsUrl($account, feature: 'calendar'), server: $this->worker);

        self::assertResponseStatusCodeSame(403);
        $this->entityManager()->clear();
        $account = $this->onlyAccount();
        self::assertSame('not_granted', $account->getCalendarStatus()->value);
        self::assertSame('Tresso is not allowed to use the calendars of this account yet. Allow it by signing in with Google again.', $account->getCalendarStatusDetail());
        self::assertSame('active', $account->getStatus()->value, 'Mail goes on.');
        self::assertSame('google-access-1', $this->accessToken($account));
        self::assertCount(1, $this->router()->requestsTo(self::GOOGLE_TOKEN), 'Refusing a feature asks the provider nothing.');

        $this->client->request('GET', $this->credentialsUrl($account, feature: 'contacts'), server: $this->worker);
        self::assertResponseStatusCodeSame(400);
    }

    public function testCalendarTokensComeOnceTheGrantCoversCalendars(): void
    {
        $query = $this->start('google', features: ['mail', 'calendar']);
        self::assertSame('openid email profile '.self::GMAIL_SCOPE.' '.self::GOOGLE_CALENDAR_SCOPES, $query['scope']);
        $this->googleTokens(scope: 'openid https://www.googleapis.com/auth/userinfo.email '.self::GMAIL_SCOPE.' '.self::GOOGLE_CALENDAR_SCOPES);
        $this->returnFromProvider('google', $query['state']);
        $account = $this->onlyAccount();
        $this->lease();

        $credentials = $this->credentials($account, feature: 'calendar');

        self::assertSame('google-access-1', self::secrets($credentials)['accessToken'] ?? null);
    }

    public function testMicrosoftCalendarsNeedCalendarsReadWrite(): void
    {
        $account = $this->connectMicrosoft();
        $this->lease();

        $this->client->request('GET', $this->credentialsUrl($account, feature: 'calendar'), server: $this->worker);
        self::assertResponseStatusCodeSame(403);

        $this->connection()->executeStatement('UPDATE provider_grant SET scopes = \'["calendars.readwrite","mail.readwrite","mail.send","user.read"]\'');
        self::assertSame('ms-access-1', self::secrets($this->credentials($account, feature: 'calendar'))['accessToken'] ?? null);
    }

    public function testWithdrawingOnlyTheCalendarConsentAtMicrosoftKeepsTheMail(): void
    {
        $account = $this->connectMicrosoft();
        $this->connection()->executeStatement('UPDATE provider_grant SET scopes = \'["calendars.readwrite","mail.readwrite","mail.send","user.read"]\'');
        $this->sealTokens($account, accessTokenExpiresAt: time() + 60);
        $this->lease();
        $this->router()->respondOnce(self::MICROSOFT_TOKEN, '{"error":"invalid_grant","error_description":"AADSTS65001: The user or administrator has not consented to use the application."}', 400);
        $this->router()->respondOnce(self::MICROSOFT_TOKEN, (string) json_encode(['access_token' => 'ms-access-2', 'expires_in' => 3600, 'refresh_token' => 'ms-refresh-2', 'scope' => 'https://graph.microsoft.com/Mail.ReadWrite https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read', 'token_type' => 'Bearer']));

        self::assertSame('ms-access-2', $this->accessToken($account));

        $refreshes = $this->router()->requestsTo(self::MICROSOFT_TOKEN);
        self::assertStringContainsString('Calendars.ReadWrite', urldecode($refreshes[\count($refreshes) - 2]['body']));
        self::assertStringNotContainsString('Calendars.ReadWrite', urldecode($refreshes[\count($refreshes) - 1]['body']), 'The second refresh asks for mail alone.');
        $this->entityManager()->clear();
        $account = $this->onlyAccount();
        self::assertSame('active', $account->getStatus()->value);
        self::assertSame('not_granted', $account->getCalendarStatus()->value);
        $grant = $account->getProviderGrant();
        self::assertInstanceOf(ProviderGrant::class, $grant);
        self::assertNotContains('calendars.readwrite', $grant->getScopes());

        $this->client->request('GET', $this->credentialsUrl($account, feature: 'calendar'), server: $this->worker);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnUnreachableProviderAsksTheWorkerToRetry(): void
    {
        $account = $this->connectGoogle();
        $this->sealTokens($account, accessTokenExpiresAt: time() + 60);
        $this->lease();
        $this->router()->respondOnce(self::GOOGLE_TOKEN, 'upstream error', 502);

        $this->client->request('GET', $this->credentialsUrl($account), server: $this->worker);

        self::assertResponseStatusCodeSame(503);
        self::assertResponseHeaderSame('Retry-After', '30');
        $this->entityManager()->clear();
        self::assertSame('active', $this->onlyAccount()->getStatus()->value, 'An outage is not the user\'s problem to fix.');
    }

    private function connectMicrosoft(): MailAccount
    {
        $query = $this->start('microsoft');
        $this->router()->respond(self::MICROSOFT_TOKEN, (string) json_encode([
            'access_token' => 'ms-access-1',
            'expires_in' => 3600,
            'refresh_token' => 'ms-refresh-1',
            'scope' => 'https://graph.microsoft.com/Mail.ReadWrite https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read',
            'id_token' => $this->idToken(['aud' => 'test-microsoft-client', 'tid' => 'tenant-1', 'oid' => 'object-1']),
        ]));
        $this->router()->respond(self::GRAPH_ME, (string) json_encode(['mail' => 'kim@contoso.example', 'displayName' => 'Kim']));
        $this->returnFromProvider('microsoft', $query['state']);

        return $this->onlyAccount();
    }

    /**
     * Rewrites the sealed tokens, to put the cached access token at a given age or expiry.
     */
    private function sealTokens(MailAccount $account, ?int $accessTokenExpiresAt = null, ?int $accessTokenIssuedAt = null): void
    {
        $grant = $this->entityManager()->find(ProviderGrant::class, $account->getProviderGrant()?->getId());
        self::assertInstanceOf(ProviderGrant::class, $grant);
        $tokens = $this->vault()->openGrant($grant);
        $this->vault()->sealGrant($grant, [
            'accessTokenExpiresAt' => $accessTokenExpiresAt ?? $tokens['accessTokenExpiresAt'] ?? null,
            'accessTokenIssuedAt' => $accessTokenIssuedAt ?? $tokens['accessTokenIssuedAt'] ?? null,
        ] + $tokens);
        $this->entityManager()->flush();
        $this->entityManager()->clear();
    }

    private function lease(): void
    {
        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => 'w1'], $this->worker);
        self::assertResponseIsSuccessful();
        $accounts = $this->decodedResponse()['accounts'] ?? null;
        self::assertIsArray($accounts);
        self::assertCount(1, $accounts);
    }

    /**
     * @return array<string, mixed>
     */
    private function credentials(MailAccount $account, bool $rejected = false, ?string $feature = null): array
    {
        $this->client->request('GET', $this->credentialsUrl($account, $rejected, $feature), server: $this->worker);
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> */
        return $this->decodedResponse();
    }

    private function accessToken(MailAccount $account, bool $rejected = false): string
    {
        $token = self::secrets($this->credentials($account, $rejected))['accessToken'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    private function credentialsUrl(MailAccount $account, bool $rejected = false, ?string $feature = null): string
    {
        return '/internal/sync/accounts/'.$account->getId()->toRfc4122().'/credentials?'.http_build_query(['workerId' => 'w1'] + ($rejected ? ['rejected' => '1'] : []) + (null === $feature ? [] : ['feature' => $feature]));
    }
}
