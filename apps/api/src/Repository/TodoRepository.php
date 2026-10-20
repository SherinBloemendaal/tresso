<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Message;
use App\Entity\Todo;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Todo>
 */
class TodoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Todo::class);
    }

    /**
     * The owner's todos: open ones in their order, then done ones, most recently completed first.
     *
     * @return list<Todo>
     */
    public function findOwnedBy(User $owner): array
    {
        /** @var list<Todo> */
        return $this->createQueryBuilder('t')
            ->addSelect('CASE WHEN t.completedAt IS NULL THEN 0 ELSE 1 END AS HIDDEN done')
            ->where('t.owner = :owner')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->orderBy('done')
            ->addOrderBy('t.position')
            ->addOrderBy('t.completedAt', 'DESC')
            ->addOrderBy('t.id')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the todo only when it belongs to the owner, so other users' todos look like they do not exist.
     */
    public function findOneOwnedBy(User $owner, string $id): ?Todo
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($id), 'owner' => $owner]);
    }

    public function findOneByMessage(Message $message): ?Todo
    {
        return $this->findOneBy(['message' => $message]);
    }

    /**
     * Which of these messages have a todo already.
     *
     * @param list<string> $messageIds
     *
     * @return array<string, true> by message id
     */
    public function messagesWithTodo(array $messageIds): array
    {
        if ([] === $messageIds) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT message_id FROM todo WHERE message_id = ANY(CAST(:ids AS UUID[]))',
            ['ids' => '{'.implode(',', $messageIds).'}'],
        );

        return array_fill_keys($ids, true);
    }

    /** The position after the owner's last open todo, so a new one goes at the bottom. */
    public function nextPosition(User $owner): int
    {
        $max = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT MAX(position) FROM todo WHERE owner_id = :owner AND completed_at IS NULL',
            ['owner' => $owner->getId()->toRfc4122()],
        );

        return is_numeric($max) ? (int) $max + 1 : 0;
    }
}
