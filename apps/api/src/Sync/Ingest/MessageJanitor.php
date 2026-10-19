<?php

declare(strict_types=1);

namespace App\Sync\Ingest;

use App\Entity\MailAccount;
use Doctrine\DBAL\Connection;

/**
 * Messages exist as long as they are in at least one folder. After locations were removed, this deletes messages
 * that are nowhere anymore, deletes threads that became empty and recounts the threads that lost messages.
 *
 * Works in SQL: call it after flushing, and clear the entity manager afterwards.
 */
final readonly class MessageJanitor
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return int number of deleted messages
     */
    public function removeOrphans(MailAccount $account): int
    {
        $accountId = $account->getId()->toRfc4122();

        /** @var list<string> $threadIds */
        $threadIds = $this->connection->fetchFirstColumn(
            'DELETE FROM message m
             WHERE m.mail_account_id = :account
               AND NOT EXISTS (SELECT 1 FROM message_location l WHERE l.message_id = m.id)
             RETURNING m.thread_id',
            ['account' => $accountId],
        );

        if ([] === $threadIds) {
            return 0;
        }

        $threads = '{'.implode(',', array_unique($threadIds)).'}';
        $this->connection->executeStatement(
            'DELETE FROM thread t WHERE t.id = ANY(:threads::uuid[]) AND NOT EXISTS (SELECT 1 FROM message m WHERE m.thread_id = t.id)',
            ['threads' => $threads],
        );
        $this->connection->executeStatement(
            'UPDATE thread t SET message_count = s.message_count, last_message_at = s.last_message_at
             FROM (SELECT thread_id, COUNT(*) AS message_count, MAX(received_at) AS last_message_at
                   FROM message WHERE thread_id = ANY(:threads::uuid[]) GROUP BY thread_id) s
             WHERE t.id = s.thread_id',
            ['threads' => $threads],
        );

        return \count($threadIds);
    }
}
