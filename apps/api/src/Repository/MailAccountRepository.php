<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MailAccount;
use App\Entity\ProviderGrant;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<MailAccount>
 */
class MailAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailAccount::class);
    }

    /** @return list<MailAccount> */
    public function findOwnedBy(User $owner): array
    {
        /** @var list<MailAccount> */
        return $this->createQueryBuilder('a')
            ->where('a.owner = :owner')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->orderBy('a.createdAt')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the account only when it belongs to the owner, so other users' accounts look like they do not exist.
     */
    public function findOneOwnedBy(User $owner, string $id): ?MailAccount
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($id), 'owner' => $owner]);
    }

    public function existsForOwner(User $owner, string $emailAddress): bool
    {
        return null !== $this->findOneBy(['owner' => $owner, 'emailAddress' => mb_strtolower($emailAddress)]);
    }

    public function findOneByOwnerAndAddress(User $owner, string $emailAddress): ?MailAccount
    {
        return $this->findOneBy(['owner' => $owner, 'emailAddress' => mb_strtolower($emailAddress)]);
    }

    /** @return list<MailAccount> */
    public function findByProviderGrant(ProviderGrant $grant): array
    {
        /** @var list<MailAccount> */
        return $this->findBy(['providerGrant' => $grant]);
    }
}
