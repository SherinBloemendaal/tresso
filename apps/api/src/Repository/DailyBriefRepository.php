<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DailyBrief;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<DailyBrief>
 */
class DailyBriefRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyBrief::class);
    }

    /**
     * The owner's briefs beyond the newest $keep.
     *
     * @return list<DailyBrief>
     */
    public function olderThanNewest(User $owner, int $keep): array
    {
        /** @var list<DailyBrief> */
        return $this->createQueryBuilder('b')
            ->where('b.owner = :owner')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->orderBy('b.coversUntil', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->setFirstResult($keep)
            ->getQuery()
            ->getResult();
    }

    public function findOneById(string $id): ?DailyBrief
    {
        return Uuid::isValid($id) ? $this->find(Uuid::fromString($id)) : null;
    }
}
