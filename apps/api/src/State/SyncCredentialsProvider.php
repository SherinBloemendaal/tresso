<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\SyncCredentials;
use App\Enum\CalendarSyncStatus;
use App\Enum\ProviderFeature;
use App\ProviderAuth\FeatureNotGranted;
use App\ProviderAuth\ProviderAccessTokens;
use App\ProviderAuth\ProviderUnavailable;
use App\ProviderAuth\ReauthorizationRequired;
use App\Security\Crypto\CredentialVault;
use App\Sync\LeaseGuard;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Hands out credentials only for an account the calling worker (?workerId=...) holds an unexpired lease on.
 * Every other case is a 404, so the endpoint does not reveal which accounts exist.
 *
 * ?feature=calendar asks for an access token that also carries the calendar scopes. While the user has not allowed
 * calendars, that is a 403 and the account's calendar status says how to allow them; mail is not affected.
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
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SyncCredentials
    {
        $query = $this->requests->getCurrentRequest()?->query;
        $account = $this->leaseGuard->leasedAccount($uriVariables['accountId'] ?? null, $query?->get('workerId'));

        $feature = ProviderFeature::tryFrom((string) ($query?->get('feature') ?? ProviderFeature::Mail->value))
            ?? throw new BadRequestHttpException('Unknown feature.');

        $grant = $account->getProviderGrant();
        if (null === $grant) {
            $credentials = $this->vault->open($account);
        } else {
            try {
                $token = $this->accessTokens->accessToken($grant, $feature, '1' === $query?->get('rejected'));
            } catch (FeatureNotGranted $e) {
                if (CalendarSyncStatus::NotGranted !== $account->getCalendarStatus()) {
                    // Clients see the status through the delta, whose change numbers need a transaction.
                    $this->entityManager->wrapInTransaction(static fn () => $account->setCalendarStatus(CalendarSyncStatus::NotGranted, $e->getMessage()));
                }

                throw new AccessDeniedHttpException($e->getMessage(), $e);
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
