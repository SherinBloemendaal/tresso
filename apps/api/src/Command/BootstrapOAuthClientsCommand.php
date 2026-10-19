<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\Scope;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AbstractClient;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\OAuth2Grants;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope as OAuthScope;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * Creates or updates the first-party OAuth2 clients. Safe to run on every deploy.
 */
#[AsCommand(name: 'app:oauth:bootstrap-clients', description: 'Create or update the first-party OAuth2 clients')]
final readonly class BootstrapOAuthClientsCommand
{
    public const string WEB = 'web';
    public const string DESKTOP = 'desktop';
    public const string MOBILE = 'mobile';
    public const string SYNC_WORKER = 'sync-worker';

    public function __construct(
        private ClientManagerInterface $clients,
        #[Autowire(service: 'league.oauth2_server.password_hasher')]
        private PasswordHasherInterface $secretHasher,
        #[Autowire(env: 'WEB_APP_URL')]
        private string $webAppUrl,
        #[Autowire(env: 'NATIVE_REDIRECT_URI')]
        private string $nativeRedirectUri,
        #[Autowire(env: 'SYNC_WORKER_CLIENT_SECRET')]
        private string $syncWorkerSecret,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $userScopes = array_map(static fn (Scope $scope): string => $scope->value, Scope::userScopes());
        $webRedirectUri = rtrim($this->webAppUrl, '/').'/auth/callback';
        $nativeRedirectUri = $this->nativeRedirectUri;
        if ('' === $nativeRedirectUri) {
            $io->error('NATIVE_REDIRECT_URI is empty.');

            return Command::FAILURE;
        }
        $publicGrants = [OAuth2Grants::AUTHORIZATION_CODE, OAuth2Grants::REFRESH_TOKEN];

        $this->upsert(self::WEB, 'Web app', null, $publicGrants, [$webRedirectUri], $userScopes);
        $this->upsert(self::DESKTOP, 'Desktop app', null, $publicGrants, [$nativeRedirectUri], $userScopes);
        $this->upsert(self::MOBILE, 'Mobile app', null, $publicGrants, [$nativeRedirectUri], $userScopes);

        $syncWorkerSecret = $this->syncWorkerSecret;
        if ('' === $syncWorkerSecret) {
            $io->error('SYNC_WORKER_CLIENT_SECRET is empty; refusing to create a sync worker client without a secret.');

            return Command::FAILURE;
        }
        $this->upsert(
            self::SYNC_WORKER,
            'Sync worker',
            $syncWorkerSecret,
            [OAuth2Grants::CLIENT_CREDENTIALS],
            [],
            array_map(static fn (Scope $scope): string => $scope->value, Scope::syncScopes()),
        );

        $io->success('First-party OAuth2 clients are up to date.');

        return Command::SUCCESS;
    }

    /**
     * @param non-empty-string       $identifier
     * @param list<non-empty-string> $grants
     * @param list<non-empty-string> $redirectUris
     * @param list<non-empty-string> $scopes
     */
    private function upsert(string $identifier, string $name, #[\SensitiveParameter] ?string $plainSecret, array $grants, array $redirectUris, array $scopes): void
    {
        $hashedSecret = null === $plainSecret ? null : $this->secretHasher->hash($plainSecret);

        $client = $this->clients->find($identifier);
        if (null === $client) {
            $client = new Client($name, $identifier, $hashedSecret);
        } else {
            if (!$client instanceof AbstractClient) {
                throw new \LogicException(\sprintf('OAuth2 client "%s" is not a %s.', $identifier, AbstractClient::class));
            }
            $client->setName($name);
            if (null !== $hashedSecret) {
                $client->setSecret($hashedSecret);
            }
        }

        $client->setActive(true);
        $client->setAllowPlainTextPkce(false);
        $client->setGrants(...array_map(static fn (string $grant): Grant => new Grant($grant), $grants));
        $client->setRedirectUris(...array_map(static fn (string $uri): RedirectUri => new RedirectUri($uri), $redirectUris));
        $client->setScopes(...array_map(static fn (string $scope): OAuthScope => new OAuthScope($scope), $scopes));

        $this->clients->save($client);
    }
}
