<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\SyncCredentials;
use App\Security\Crypto\CredentialVault;
use App\Sync\LeaseGuard;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Hands out credentials only for an account the calling worker (?workerId=...) holds an unexpired lease on.
 * Every other case is a 404, so the endpoint does not reveal which accounts exist.
 *
 * @implements ProviderInterface<SyncCredentials>
 */
final readonly class SyncCredentialsProvider implements ProviderInterface
{
    public function __construct(
        private LeaseGuard $leaseGuard,
        private CredentialVault $vault,
        private RequestStack $requests,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SyncCredentials
    {
        $account = $this->leaseGuard->leasedAccount(
            $uriVariables['accountId'] ?? null,
            $this->requests->getCurrentRequest()?->query->get('workerId'),
        );

        return new SyncCredentials(
            $account->getId()->toRfc4122(),
            $account->getEmailAddress(),
            $account->getProvider(),
            $account->getConnection(),
            $this->vault->open($account),
        );
    }
}
