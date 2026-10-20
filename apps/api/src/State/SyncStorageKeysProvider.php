<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\SyncStorageKeys;
use App\Security\Crypto\UserKeys;
use App\Sync\LeaseGuard;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Hands out the account owner's storage keys only for an account the calling worker (?workerId=...) holds an
 * unexpired lease on. Every other case is a 404, so the endpoint does not reveal which accounts exist.
 *
 * @implements ProviderInterface<SyncStorageKeys>
 */
final readonly class SyncStorageKeysProvider implements ProviderInterface
{
    public function __construct(
        private LeaseGuard $leaseGuard,
        private UserKeys $keys,
        private RequestStack $requests,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SyncStorageKeys
    {
        $account = $this->leaseGuard->leasedAccount($uriVariables['accountId'] ?? null, $this->requests->getCurrentRequest()?->query->get('workerId'));
        $owner = $account->getOwner()->getId();

        $blobKeys = [];
        foreach ($this->keys->versions($owner) as $version) {
            $blobKeys[] = ['version' => $version, 'key' => base64_encode(UserKeys::blobKey($this->keys->version($owner, $version)))];
        }

        return new SyncStorageKeys(
            $account->getId()->toRfc4122(),
            $owner->toRfc4122(),
            base64_encode($this->keys->indexKey($owner)),
            $this->keys->current($owner)->version,
            $blobKeys,
        );
    }
}
