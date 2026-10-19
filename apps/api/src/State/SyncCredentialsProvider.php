<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\SyncCredentials;
use App\Enum\ProviderFeature;
use App\ProviderAuth\ProviderAccessTokens;
use App\ProviderAuth\ProviderUnavailable;
use App\ProviderAuth\ReauthorizationRequired;
use App\Security\Crypto\CredentialVault;
use App\Sync\LeaseGuard;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Hands out credentials only for an account the calling worker (?workerId=...) holds an unexpired lease on.
 * Every other case is a 404, so the endpoint does not reveal which accounts exist.
 *
 * @implements ProviderInterface<SyncCredentials>
 */
final readonly class SyncCredentialsProvider implements ProviderInterface
{
    /** Seconds a worker waits before asking again when the provider is unreachable. */
    private const int RETRY_AFTER = 30;

    public function __construct(
        private LeaseGuard $leaseGuard,
        private CredentialVault $vault,
        private ProviderAccessTokens $accessTokens,
        private RequestStack $requests,
        private LoggerInterface $logger,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SyncCredentials
    {
        $query = $this->requests->getCurrentRequest()?->query;
        $account = $this->leaseGuard->leasedAccount($uriVariables['accountId'] ?? null, $query?->get('workerId'));

        $grant = $account->getProviderGrant();
        if (null === $grant) {
            $credentials = $this->vault->open($account);
        } else {
            try {
                $token = $this->accessTokens->accessToken($grant, ProviderFeature::Mail, '1' === $query?->get('rejected'));
            } catch (ReauthorizationRequired $e) {
                throw new ConflictHttpException($e->getMessage(), $e);
            } catch (ProviderUnavailable $e) {
                $this->logger->warning('No provider access token for a sync worker.', ['account' => $account->getId()->toRfc4122(), 'error' => $e->getMessage()]);

                throw new ServiceUnavailableHttpException(self::RETRY_AFTER, 'The provider could not be reached. Try again later.', $e);
            }
            $credentials = ['type' => 'oauth2', 'accessToken' => $token->token, 'expiresAt' => $token->expiresAt->format(\DATE_ATOM)];
        }

        return new SyncCredentials(
            $account->getId()->toRfc4122(),
            $account->getEmailAddress(),
            $account->getProvider(),
            $account->getConnection(),
            $credentials,
        );
    }
}
