<?php

declare(strict_types=1);

namespace App\ProviderAuth;

use App\Enum\OAuthProvider;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Talks to the providers' OAuth endpoints: builds authorization URLs, redeems codes, refreshes and revokes tokens,
 * and finds out who signed in.
 */
final readonly class ProviderClient
{
    private const float TIMEOUT = 10.0;

    public function __construct(
        private ProviderSettings $settings,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $scopes as sent to the provider
     */
    public function authorizationUrl(OAuthProvider $provider, array $scopes, string $state, string $codeChallenge, ?string $loginHint): string
    {
        $query = [
            'client_id' => $this->settings->clientId($provider),
            'redirect_uri' => $this->settings->redirectUri($provider),
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];
        $query += match ($provider) {
            // offline + consent: Google returns a refresh token only on a consent, and every connect must yield one.
            // include_granted_scopes keeps the scopes granted earlier, so a new feature adds to the grant.
            OAuthProvider::Google => ['access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true'],
            // The user picks the account, so a browser signed in to another Microsoft account is not used silently.
            OAuthProvider::Microsoft => ['response_mode' => 'query', 'prompt' => 'select_account'],
        };
        if (null !== $loginHint) {
            $query['login_hint'] = $loginHint;
        }

        return $this->settings->authorizationEndpoint($provider).'?'.http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
    }

    /**
     * @param list<string> $scopes as requested
     *
     * @throws GrantRejected
     * @throws ProviderUnavailable
     */
    public function redeemCode(OAuthProvider $provider, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $codeVerifier, array $scopes): TokenSet
    {
        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->settings->redirectUri($provider),
            'code_verifier' => $codeVerifier,
        ];
        if (OAuthProvider::Microsoft === $provider) {
            $body['scope'] = implode(' ', $scopes);
        }

        return $this->tokenRequest($provider, $body);
    }

    /**
     * @param list<string> $scopes as requested; Microsoft issues the access token for these
     *
     * @throws GrantRejected
     * @throws ProviderUnavailable
     */
    public function refresh(OAuthProvider $provider, #[\SensitiveParameter] string $refreshToken, array $scopes): TokenSet
    {
        $body = ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken];
        if (OAuthProvider::Microsoft === $provider) {
            $body['scope'] = implode(' ', $scopes);
        }

        return $this->tokenRequest($provider, $body);
    }

    /**
     * Who signed in. The ID token comes straight from the token endpoint over TLS, so its claims are trusted without a
     * signature check (OpenID Connect Core 3.1.3.7); its audience must still be this app.
     *
     * @throws ProviderUnavailable
     */
    public function identify(OAuthProvider $provider, TokenSet $tokens): ProviderIdentity
    {
        $claims = $this->idTokenClaims($provider, $tokens->idToken);

        return match ($provider) {
            OAuthProvider::Google => $this->googleIdentity($claims),
            OAuthProvider::Microsoft => $this->microsoftIdentity($claims, $tokens->accessToken),
        };
    }

    /**
     * Revokes a refresh token where the provider supports it. Failures are logged, not thrown: the grant is gone on
     * our side either way, and the user can still remove access in the provider's account settings.
     */
    public function revoke(OAuthProvider $provider, #[\SensitiveParameter] string $refreshToken): void
    {
        $endpoint = $this->settings->revocationEndpoint($provider);
        if (null === $endpoint) {
            return;
        }

        try {
            $status = $this->httpClient->request('POST', $endpoint, ['body' => ['token' => $refreshToken], 'timeout' => self::TIMEOUT])->getStatusCode();
            if ($status >= 300) {
                $this->logger->warning('Revoking a provider token failed.', ['provider' => $provider->value, 'status' => $status]);
            }
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Revoking a provider token failed.', ['provider' => $provider->value, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, string> $body
     */
    private function tokenRequest(OAuthProvider $provider, #[\SensitiveParameter] array $body): TokenSet
    {
        $body += ['client_id' => $this->settings->clientId($provider), 'client_secret' => $this->settings->clientSecret($provider)];

        try {
            $response = $this->httpClient->request('POST', $this->settings->tokenEndpoint($provider), [
                'body' => $body,
                'headers' => ['Accept' => 'application/json'],
                'timeout' => self::TIMEOUT,
            ]);
            $status = $response->getStatusCode();
            $data = $this->decode($response);
        } catch (ExceptionInterface|\JsonException $e) {
            throw new ProviderUnavailable(\sprintf('The %s token endpoint could not be reached: %s', $provider->label(), $e->getMessage()), previous: $e);
        }

        if (200 !== $status) {
            $error = \is_string($data['error'] ?? null) ? $data['error'] : 'unknown';
            // Microsoft also answers interaction_required (multi-factor or conditional access now asks for the user) and
            // consent_required (consent was withdrawn): only a new sign-in helps there too.
            if (\in_array($error, ['invalid_grant', 'interaction_required', 'consent_required'], true)) {
                throw new GrantRejected(\sprintf('%s refused the grant (%s).', $provider->label(), $error));
            }
            // invalid_client and friends mean this installation's OAuth app is misconfigured, not the user's grant.
            $this->logger->error('A provider token request failed.', ['provider' => $provider->value, 'status' => $status, 'error' => $error]);

            throw new ProviderUnavailable(\sprintf('The %s token endpoint answered %d (%s).', $provider->label(), $status, $error));
        }

        $accessToken = $data['access_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;
        if (!\is_string($accessToken) || '' === $accessToken || !is_numeric($expiresIn)) {
            throw new ProviderUnavailable(\sprintf('The %s token endpoint returned no access token.', $provider->label()));
        }
        $refreshToken = $data['refresh_token'] ?? null;
        $scope = $data['scope'] ?? null;
        $idToken = $data['id_token'] ?? null;

        return new TokenSet(
            $accessToken,
            new \DateTimeImmutable()->modify(\sprintf('+%d seconds', (int) $expiresIn)),
            \is_string($refreshToken) && '' !== $refreshToken ? $refreshToken : null,
            \is_string($scope) ? ProviderScopes::normalize($provider, $scope) : null,
            \is_string($idToken) ? $idToken : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function idTokenClaims(OAuthProvider $provider, ?string $idToken): array
    {
        $parts = null === $idToken ? [] : explode('.', $idToken);
        $payload = 3 === \count($parts) ? base64_decode(strtr($parts[1], '-_', '+/'), true) : false;
        $claims = false === $payload ? null : json_decode($payload, true);
        if (!\is_array($claims)) {
            throw new ProviderUnavailable(\sprintf('%s returned no readable ID token.', $provider->label()));
        }

        $audience = $claims['aud'] ?? null;
        $clientId = $this->settings->clientId($provider);
        if ($audience !== $clientId && !(\is_array($audience) && \in_array($clientId, $audience, true))) {
            throw new ProviderUnavailable(\sprintf('The %s ID token is not meant for this app.', $provider->label()));
        }

        /** @var array<string, mixed> $claims */
        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function googleIdentity(array $claims): ProviderIdentity
    {
        $subject = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;
        if (!\is_string($subject) || '' === $subject || !\is_string($email) || '' === $email) {
            throw new ProviderUnavailable('The Google ID token has no subject or address.');
        }
        if (true !== ($claims['email_verified'] ?? null) && 'true' !== ($claims['email_verified'] ?? null)) {
            throw new UnverifiedAddress($email);
        }
        $name = $claims['name'] ?? null;

        return new ProviderIdentity($subject, $email, \is_string($name) && '' !== $name ? $name : null);
    }

    /**
     * Microsoft's ID token may lack the address (and its "email" claim is not verified), so the mailbox address comes
     * from Graph: the user's primary SMTP address, or the sign-in name for accounts without one.
     *
     * @param array<string, mixed> $claims
     */
    private function microsoftIdentity(array $claims, #[\SensitiveParameter] string $accessToken): ProviderIdentity
    {
        $tenant = $claims['tid'] ?? null;
        $object = $claims['oid'] ?? null;
        if (!\is_string($tenant) || '' === $tenant || !\is_string($object) || '' === $object) {
            throw new ProviderUnavailable('The Microsoft ID token has no tenant or object id.');
        }

        try {
            $response = $this->httpClient->request('GET', 'https://graph.microsoft.com/v1.0/me', [
                'query' => ['$select' => 'mail,userPrincipalName,displayName'],
                'auth_bearer' => $accessToken,
                'headers' => ['Accept' => 'application/json'],
                'timeout' => self::TIMEOUT,
            ]);
            $status = $response->getStatusCode();
            $me = $this->decode($response);
        } catch (ExceptionInterface|\JsonException $e) {
            throw new ProviderUnavailable('Microsoft Graph could not be reached: '.$e->getMessage(), previous: $e);
        }
        if (200 !== $status) {
            throw new ProviderUnavailable(\sprintf('Microsoft Graph answered %d for the signed-in user.', $status));
        }

        $mail = $me['mail'] ?? null;
        $principal = $me['userPrincipalName'] ?? null;
        $email = \is_string($mail) && '' !== $mail ? $mail : (\is_string($principal) ? $principal : '');
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new NoMailbox();
        }
        $name = $me['displayName'] ?? null;

        return new ProviderIdentity($tenant.':'.$object, $email, \is_string($name) && '' !== $name ? $name : null);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ExceptionInterface
     * @throws \JsonException
     */
    private function decode(ResponseInterface $response): array
    {
        $content = $response->getContent(false);
        $data = '' === $content ? [] : json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> */
        return \is_array($data) ? $data : [];
    }
}
