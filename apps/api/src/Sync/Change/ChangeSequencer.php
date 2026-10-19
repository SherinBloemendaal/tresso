<?php

declare(strict_types=1);

namespace App\Sync\Change;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Hands out per-user change numbers.
 *
 * The number comes from UPDATE ... RETURNING on the user's row, which locks that row until the transaction ends. So
 * writes for one user commit in the order of their numbers: a client that saw number N can never later meet a
 * smaller number that was still uncommitted. That only holds inside a transaction, hence the guard. Different users
 * never wait on each other.
 */
final readonly class ChangeSequencer
{
    public function __construct(private Connection $connection)
    {
    }

    public function next(Uuid $userId): int
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Change numbers must be allocated inside a transaction; wrap the write in EntityManager::wrapInTransaction().');
        }

        $seq = $this->connection->fetchOne(
            'UPDATE app_user SET change_seq = change_seq + 1 WHERE id = :id RETURNING change_seq',
            ['id' => $userId->toRfc4122()],
        );
        if (!is_numeric($seq)) {
            throw new \LogicException(\sprintf('User %s does not exist.', $userId->toRfc4122()));
        }

        return (int) $seq;
    }

    /**
     * @param list<string> $entityIds
     */
    public function tombstones(Uuid $userId, string $entityType, array $entityIds, int $seq): void
    {
        foreach ($entityIds as $entityId) {
            $this->connection->insert('sync_tombstone', [
                'id' => Uuid::v7()->toRfc4122(),
                'user_id' => $userId->toRfc4122(),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'change_seq' => $seq,
            ]);
        }
    }

    /**
     * Marks messages changed whose folder membership changed in bulk SQL (the ORM listener does not see those).
     *
     * @param list<string> $messageIds
     */
    public function touchMessages(Uuid $userId, array $messageIds): void
    {
        if ([] === $messageIds) {
            return;
        }
        $this->connection->executeStatement(
            'UPDATE message SET change_seq = :seq WHERE id = ANY(CAST(:ids AS UUID[]))',
            ['seq' => $this->next($userId), 'ids' => '{'.implode(',', array_unique($messageIds)).'}'],
        );
    }
}
