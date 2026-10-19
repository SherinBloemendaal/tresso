<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MailAccount;
use App\Entity\MailboxChange;
use App\Enum\MailboxChangeKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MailboxChange>
 */
class MailboxChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailboxChange::class);
    }

    /**
     * The account's unconfirmed changes, oldest first, locked for the caller's transaction.
     *
     * @return list<MailboxChange>
     */
    public function lockPending(MailAccount $account, int $limit): array
    {
        /** @var list<MailboxChange> */
        return $this->createQueryBuilder('c')
            ->where('c.mailAccount = :account')
            ->setParameter('account', $account->getId(), 'uuid')
            ->orderBy('c.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }

    /**
     * Ids of the account's messages with a flag change the server has not confirmed yet: the folder sync must not
     * put the old flags back on them in the meantime.
     *
     * @return array<string, true>
     */
    public function messagesWithPendingFlags(MailAccount $account): array
    {
        /** @var list<string> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT message_id FROM mailbox_change WHERE mail_account_id = :account AND kind = :kind',
            ['account' => $account->getId()->toRfc4122(), 'kind' => MailboxChangeKind::Flags->value],
        );

        return array_fill_keys($ids, true);
    }

    /**
     * @param list<string> $accountIds
     *
     * @return list<string> the accounts among them with changes a worker can take now
     */
    public function accountsWithDueChanges(array $accountIds, \DateTimeImmutable $now): array
    {
        if ([] === $accountIds) {
            return [];
        }

        /** @var list<string> */
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT mail_account_id FROM mailbox_change
             WHERE mail_account_id = ANY(CAST(:ids AS UUID[]))
               AND not_before <= :now
               AND (claimed_until IS NULL OR claimed_until < :now)',
            ['ids' => '{'.implode(',', $accountIds).'}', 'now' => $now->format('Y-m-d H:i:s')],
        );
    }
}
