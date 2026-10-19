<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\SyncCredentials;
use App\Entity\MailAccount;
use App\Security\Crypto\CredentialVault;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Hands out credentials only for an account the calling worker (?workerId=...) holds an unexpired lease on.
 * Every other case is a 404, so the endpoint does not reveal which accounts exist.
 *
 * @implements ProviderInterface<SyncCredentials>
 */
final readonly class SyncCredentialsProvider implements ProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CredentialVault $vault,
        private RequestStack $requests,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SyncCredentials
    {
        $workerId = $this->requests->getCurrentRequest()?->query->get('workerId');
        if (!\is_string($workerId) || 1 !== preg_match('/^[A-Za-z0-9._:-]{1,120}$/', $workerId)) {
            throw new BadRequestHttpException('The workerId query parameter is required.');
        }

        $accountId = $uriVariables['accountId'] ?? null;
        $account = \is_string($accountId) && Uuid::isValid($accountId)
            ? $this->entityManager->find(MailAccount::class, Uuid::fromString($accountId))
            : null;

        $leaseExpiresAt = $account?->getLeaseExpiresAt();
        if (null === $account || $workerId !== $account->getLeasedBy() || null === $leaseExpiresAt || $leaseExpiresAt <= new \DateTimeImmutable()) {
            throw new NotFoundHttpException('No account with that id is leased by this worker.');
        }

        return new SyncCredentials(
            $account->getId()->toRfc4122(),
            $account->getEmailAddress(),
            $account->getProvider(),
            $account->getConnection(),
            $this->vault->open($account),
        );
    }
}
