<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DraftCopyRemoval;
use App\Entity\MailAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DraftCopyRemoval>
 */
class DraftCopyRemovalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DraftCopyRemoval::class);
    }

    /**
     * @return list<DraftCopyRemoval>
     */
    public function lockClaimable(MailAccount $account, \DateTimeImmutable $now, int $limit): array
    {
        /** @var list<DraftCopyRemoval> */
        return $this->createQueryBuilder('r')
            ->where('r.mailAccount = :account')
            ->andWhere('r.claimedUntil IS NULL OR r.claimedUntil < :now')
            ->setParameter('account', $account->getId(), 'uuid')
            ->setParameter('now', $now)
            ->setMaxResults($limit)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }

    /**
     * @param list<string> $accountIds
     *
     * @return list<string>
     */
    public function accountsWithRemovals(array $accountIds, \DateTimeImmutable $now): array
    {
        if ([] === $accountIds) {
            return [];
        }

        /** @var list<string> */
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT mail_account_id FROM draft_copy_removal
             WHERE mail_account_id = ANY(CAST(:ids AS UUID[])) AND (claimed_until IS NULL OR claimed_until < :now)',
            ['ids' => '{'.implode(',', $accountIds).'}', 'now' => $now->format('Y-m-d H:i:s')],
        );
    }
}
