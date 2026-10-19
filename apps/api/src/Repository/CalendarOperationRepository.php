<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CalendarOperation;
use App\Entity\CalendarObject;
use App\Entity\MailAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CalendarOperation>
 */
class CalendarOperationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarOperation::class);
    }

    /** The operation for this resource that no worker holds yet. */
    public function findUnclaimedFor(CalendarObject $object): ?CalendarOperation
    {
        return $this->findOneBy(['object' => $object, 'claimedBy' => null], ['createdAt' => 'ASC']);
    }

    /**
     * Operations of the account a worker may take now, oldest first, locked until the transaction ends.
     *
     * @return list<CalendarOperation>
     */
    public function lockClaimable(MailAccount $account, \DateTimeImmutable $now, int $limit): array
    {
        /** @var list<CalendarOperation> */
        return $this->createQueryBuilder('o')
            ->where('o.mailAccount = :account')
            ->andWhere('o.notBefore <= :now')
            ->andWhere('o.claimedUntil IS NULL OR o.claimedUntil < :now')
            ->setParameter('account', $account->getId(), 'uuid')
            ->setParameter('now', $now)
            ->orderBy('o.createdAt')
            ->setMaxResults($limit)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }

    /**
     * Of the given accounts, those with calendar changes to write now.
     *
     * @param list<string> $accountIds
     *
     * @return list<string>
     */
    public function accountsWithClaimable(array $accountIds, \DateTimeImmutable $now): array
    {
        if ([] === $accountIds) {
            return [];
        }

        /** @var list<string> */
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT mail_account_id FROM calendar_operation
             WHERE mail_account_id = ANY(CAST(:ids AS UUID[])) AND not_before <= :now AND (claimed_until IS NULL OR claimed_until < :now)',
            ['ids' => '{'.implode(',', $accountIds).'}', 'now' => $now->format('Y-m-d H:i:s')],
        );
    }
}
