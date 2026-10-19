<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SenderRule;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<SenderRule>
 */
class SenderRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SenderRule::class);
    }

    /** @return list<SenderRule> */
    public function findOwnedBy(User $owner): array
    {
        /** @var list<SenderRule> */
        return $this->createQueryBuilder('s')
            ->where('s.owner = :owner')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->orderBy('s.sender')
            ->addOrderBy('s.id')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the sender rule only when it belongs to the owner, so other users' rules look like they do not exist.
     */
    public function findOneOwnedBy(User $owner, string $id): ?SenderRule
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($id), 'owner' => $owner]);
    }
}
