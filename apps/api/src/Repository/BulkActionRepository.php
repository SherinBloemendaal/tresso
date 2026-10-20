<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BulkAction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<BulkAction>
 */
class BulkActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BulkAction::class);
    }

    /**
     * Returns the bulk action only when it belongs to the owner, so other users' look like they do not exist; locked
     * for the caller's transaction when asked.
     */
    public function findOneOwnedBy(User $owner, string $id, bool $lock = false): ?BulkAction
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $query = $this->createQueryBuilder('b')
            ->where('b.id = :id')
            ->andWhere('b.owner = :owner')
            ->setParameter('id', Uuid::fromString($id), 'uuid')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->getQuery();
        if ($lock) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }
        $bulkAction = $query->getOneOrNullResult();

        return $bulkAction instanceof BulkAction ? $bulkAction : null;
    }

    /** Forgets the owner's bulk actions that finished before $finishedBefore, and with them their undo. */
    public function deleteFinished(User $owner, \DateTimeImmutable $finishedBefore): void
    {
        $this->createQueryBuilder('b')
            ->delete()
            ->where('b.owner = :owner')
            ->andWhere('b.finishedAt < :before')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->setParameter('before', $finishedBefore)
            ->getQuery()
            ->execute();
    }
}
