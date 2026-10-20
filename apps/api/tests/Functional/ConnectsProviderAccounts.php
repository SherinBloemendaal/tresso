<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\MailAccount;
use App\Security\Crypto\CredentialVault;
use App\Tests\Support\MockHttpRouter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Walks through a provider sign-in like a browser does, with the providers' endpoints answered by the mock router.
 * Needs {@see AuthenticatesUsers} and a signed-in user's API headers in $this->user.
 *
 * @property KernelBrowser $client
 */
trait ConnectsProviderAccounts
{
    private const string GOOGLE_TOKEN = 'https://oauth2.googleapis.com/token';
    private const string MICROSOFT_TOKEN = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    private const string GRAPH_ME = 'https://graph.microsoft.com/v1.0/me';
    private const string GMAIL_SCOPE = 'https://www.googleapis.com/auth/gmail.modify';
    private const string GOOGLE_CALENDAR_SCOPES = 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.calendarlist.readonly';

    /** @var array<string, string> */
    private array $user;

    /**
     * Signs in with Google once and returns the new account.
     */
    private function connectGoogle(): MailAccount
    {
        $query = $this->start('google');
        $this->googleTokens();
        $this->returnFromProvider('google', $query['state']);

        return $this->onlyAccount();
    }

    /**
     * Starts a sign-in and returns the query of the authorization URL.
     *
     * @param list<string>|null $features null for the API's default
     *
     * @return array<string, string>
     */
    private function start(string $provider, ?string $mailAccountId = null, ?array $features = null): array
    {
        $this->client->jsonRequest('POST', '/api/v1/provider-authorizations', ['provider' => $provider] + (null === $mailAccountId ? [] : ['mailAccountId' => $mailAccountId]) + (null === $features ? [] : ['features' => $features]), $this->user);
        self::assertResponseStatusCodeSame(201);
        $url = $this->responseString('authorizationUrl');
        self::assertSame($provider, $this->responseString('provider'));

        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return $query;
    }

    /**
     * The provider sends the browser back; returns where the API sent it next.
     */
    private function returnFromProvider(string $provider, string $state, string $code = 'code-1', string $signInAs = 'kim@example.test'): string
    {
        $this->signInBrowser($signInAs);
        $this->client->request('GET', '/connect/'.$provider.'/callback?'.http_build_query(['state' => $state, 'code' => $code, 'scope' => 'ignored']));
        self::assertResponseStatusCodeSame(302);
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertIsString($location);
        $this->entityManager()->clear();

        return $location;
    }

    /** Signs the browser in to the API session, as the user does before /oauth/authorize. */
    private function signInBrowser(string $email): void
    {
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form(['_username' => $email, '_password' => self::DEFAULT_PASSWORD]));
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function googleTokens(string $access = 'google-access-1', ?string $refresh = 'google-refresh-1', ?string $scope = null, array $claims = []): void
    {
        $response = [
            'access_token' => $access,
            'expires_in' => 3599,
            'scope' => $scope ?? 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile '.self::GMAIL_SCOPE,
            'token_type' => 'Bearer',
            'id_token' => $this->idToken($claims + ['aud' => 'test-google-client', 'sub' => 'google-sub-1', 'email' => 'kim@gmail.example', 'email_verified' => true, 'name' => 'Kim Visser']),
        ];
        if (null !== $refresh) {
            $response['refresh_token'] = $refresh;
        }
        $this->router()->respond(self::GOOGLE_TOKEN, (string) json_encode($response));
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function idToken(array $claims): string
    {
        $encode = static fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

        return $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode($claims + ['iat' => time(), 'exp' => time() + 3600]).'.signature';
    }

    /**
     * @return list<string> property paths of the last response's violations
     */
    private function violationPaths(): array
    {
        $violations = $this->decodedResponse()['violations'] ?? null;
        self::assertIsArray($violations);

        return array_values(array_map(static fn (mixed $violation): string => \is_array($violation) && \is_string($violation['propertyPath'] ?? null) ? $violation['propertyPath'] : '', $violations));
    }

    /**
     * The credentials part of a credentials response.
     *
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private static function secrets(array $response): array
    {
        $secrets = $response['credentials'] ?? null;
        self::assertIsArray($secrets);

        /** @var array<string, mixed> */
        return $secrets;
    }

    private function onlyAccount(): MailAccount
    {
        $accounts = $this->entityManager()->getRepository(MailAccount::class)->findAll();
        self::assertCount(1, $accounts);

        return $accounts[0];
    }

    private function router(): MockHttpRouter
    {
        return static::getContainer()->get(MockHttpRouter::class);
    }

    private function vault(): CredentialVault
    {
        return static::getContainer()->get(CredentialVault::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
