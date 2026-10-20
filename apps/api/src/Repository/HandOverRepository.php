<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HandOver;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\HandOverState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<HandOver>
 */
class HandOverRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HandOver::class);
    }

    /**
     * Open ones first, by due date; then the closed ones, most recently closed first.
     *
     * @return list<HandOver>
     */
    public function findOwnedBy(User $owner): array
    {
        /** @var list<HandOver> */
        return $this->createQueryBuilder('h')
            ->where('h.owner = :owner')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->addSelect('CASE WHEN h.state = :open THEN 0 ELSE 1 END AS HIDDEN closed')
            ->setParameter('open', HandOverState::Open)
            ->orderBy('closed')
            ->addOrderBy('h.dueAt')
            ->addOrderBy('h.closedAt', 'DESC')
            ->addOrderBy('h.id')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the hand-over only when it belongs to the owner, so other users' hand-overs look like they do not exist.
     */
    public function findOneOwnedBy(User $owner, string $id): ?HandOver
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($id), 'owner' => $owner]);
    }

    /**
     * Open hand-overs a newly stored message answers: in the same conversation, from the delegate, and received after
     * the conversation was handed over.
     *
     * @return list<HandOver>
     */
    public function findAnsweredBy(Message $message): array
    {
        /** @var list<HandOver> */
        return $this->createQueryBuilder('h')
            ->join('h.message', 'm')
            ->where('h.mailAccount = :account')
            ->andWhere('h.state = :open')
            ->andWhere('h.delegateAddress = :from')
            ->andWhere('m.thread = :thread')
            ->andWhere('h.createdAt <= :receivedAt')
            ->setParameter('account', $message->getMailAccount()->getId(), 'uuid')
            ->setParameter('open', HandOverState::Open)
            ->setParameter('from', mb_strtolower($message->getFromAddress()))
            ->setParameter('thread', $message->getThread()->getId(), 'uuid')
            ->setParameter('receivedAt', $message->getReceivedAt())
            ->getQuery()
            ->getResult();
    }

    /**
     * Open hand-overs of these accounts whose due date passed and that were not marked overdue yet, locked.
     *
     * @param list<string> $accountIds
     *
     * @return list<HandOver>
     */
    public function lockDue(array $accountIds, \DateTimeImmutable $now, int $limit): array
    {
        if ([] === $accountIds) {
            return [];
        }

        /** @var list<HandOver> */
        return $this->createQueryBuilder('h')
            ->where('h.mailAccount IN (:accounts)')
            ->andWhere('h.state = :open')
            ->andWhere('h.dueAt <= :now')
            ->andWhere('h.overdueAt IS NULL')
            ->setParameter('accounts', $accountIds)
            ->setParameter('open', HandOverState::Open)
            ->setParameter('now', $now)
            ->orderBy('h.dueAt')
            ->setMaxResults($limit)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }
}
