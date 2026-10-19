<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MailAccount;
use App\Entity\OutgoingMessage;
use App\Entity\User;
use App\Enum\OutgoingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<OutgoingMessage>
 */
class OutgoingMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OutgoingMessage::class);
    }

    public function findOneOwnedBy(User $owner, string $id): ?OutgoingMessage
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $message = $this->createQueryBuilder('o')
            ->join('o.mailAccount', 'a')
            ->where('o.id = :id')
            ->andWhere('a.owner = :owner')
            ->setParameter('id', Uuid::fromString($id), 'uuid')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $message instanceof OutgoingMessage ? $message : null;
    }

    /**
     * Messages of the account a worker may send now: due, and not held by a worker whose claim is still valid. Locked
     * until the transaction ends, so two workers never claim the same message.
     *
     * @return list<OutgoingMessage>
     */
    public function lockClaimable(MailAccount $account, \DateTimeImmutable $now, int $limit): array
    {
        /** @var list<OutgoingMessage> */
        return $this->createQueryBuilder('o')
            ->where('o.mailAccount = :account')
            ->andWhere('(o.status = :queued AND o.sendAt <= :now) OR (o.status = :sending AND o.claimedUntil < :now)')
            ->setParameter('account', $account->getId(), 'uuid')
            ->setParameter('queued', OutgoingStatus::Queued)
            ->setParameter('sending', OutgoingStatus::Sending)
            ->setParameter('now', $now)
            ->orderBy('o.sendAt')
            ->setMaxResults($limit)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }

    /**
     * Scheduled messages of these accounts whose time has come.
     *
     * @param list<string> $accountIds
     *
     * @return list<OutgoingMessage>
     */
    public function lockDue(array $accountIds, \DateTimeImmutable $now, int $limit): array
    {
        if ([] === $accountIds) {
            return [];
        }

        /** @var list<OutgoingMessage> */
        return $this->createQueryBuilder('o')
            ->where('o.mailAccount IN (:accounts)')
            ->andWhere('o.status = :scheduled')
            ->andWhere('o.sendAt <= :now')
            ->setParameter('accounts', $accountIds)
            ->setParameter('scheduled', OutgoingStatus::Scheduled)
            ->setParameter('now', $now)
            ->orderBy('o.sendAt')
            ->setMaxResults($limit)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }

    /**
     * Of the given accounts, those with mail to send now.
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
            'SELECT DISTINCT mail_account_id FROM outgoing_message
             WHERE mail_account_id = ANY(CAST(:ids AS UUID[]))
               AND ((status = :queued AND send_at <= :now) OR (status = :sending AND claimed_until < :now))',
            ['ids' => '{'.implode(',', $accountIds).'}', 'queued' => OutgoingStatus::Queued->value, 'sending' => OutgoingStatus::Sending->value, 'now' => $now->format('Y-m-d H:i:s')],
        );
    }
}
