<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Draft;
use App\Entity\MailAccount;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Draft>
 */
class DraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Draft::class);
    }

    public function findOneOwnedBy(User $owner, string $id): ?Draft
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($id), 'owner' => $owner]);
    }

    /**
     * Drafts of the account whose newest version is not in the Drafts folder yet and that nobody files right now,
     * once they rested since the last edit. Locked until the transaction ends.
     *
     * @return list<Draft>
     */
    public function lockFilable(MailAccount $account, \DateTimeImmutable $now, \DateTimeImmutable $restedSince, int $limit): array
    {
        /** @var list<Draft> */
        return $this->createQueryBuilder('d')
            ->where('d.mailAccount = :account')
            ->andWhere('d.version > d.filedVersion')
            ->andWhere('d.updatedAt <= :rested')
            ->andWhere('d.filingClaimedUntil IS NULL OR d.filingClaimedUntil < :now')
            ->setParameter('account', $account->getId(), 'uuid')
            ->setParameter('rested', $restedSince)
            ->setParameter('now', $now)
            ->orderBy('d.updatedAt')
            ->setMaxResults($limit)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }

    /**
     * Of the given accounts, those with a draft to file.
     *
     * @param list<string> $accountIds
     *
     * @return list<string>
     */
    public function accountsWithFiling(array $accountIds, \DateTimeImmutable $now, \DateTimeImmutable $restedSince): array
    {
        if ([] === $accountIds) {
            return [];
        }

        /** @var list<string> */
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT mail_account_id FROM draft
             WHERE mail_account_id = ANY(CAST(:ids AS UUID[]))
               AND version > filed_version AND updated_at <= :rested
               AND (filing_claimed_until IS NULL OR filing_claimed_until < :now)',
            ['ids' => '{'.implode(',', $accountIds).'}', 'rested' => $restedSince->format('Y-m-d H:i:s'), 'now' => $now->format('Y-m-d H:i:s')],
        );
    }
}
