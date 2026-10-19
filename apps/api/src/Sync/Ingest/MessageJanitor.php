<?php

declare(strict_types=1);

namespace App\Sync\Ingest;

use App\Entity\MailAccount;
use App\Entity\Message;
use App\Entity\Thread;
use App\Sync\Change\ChangeSequencer;
use Doctrine\DBAL\Connection;

/**
 * Messages exist as long as they are in at least one folder. After locations were removed, this deletes messages
 * that are nowhere anymore, deletes threads that became empty, recounts the threads that lost messages, and records
 * all of it for client sync (tombstones, new change numbers).
 *
 * Works in SQL inside the caller's transaction: call it after flushing, and clear the entity manager afterwards.
 */
final readonly class MessageJanitor
{
    public function __construct(
        private Connection $connection,
        private ChangeSequencer $sequencer,
    ) {
    }

    /**
     * @return int number of deleted messages
     */
    public function removeOrphans(MailAccount $account): int
    {
        /** @var list<array{id: string, thread_id: string}> $deleted */
        $deleted = $this->connection->fetchAllAssociative(
            'DELETE FROM message m
             WHERE m.mail_account_id = :account
               AND NOT EXISTS (SELECT 1 FROM message_location l WHERE l.message_id = m.id)
             RETURNING m.id, m.thread_id',
            ['account' => $account->getId()->toRfc4122()],
        );
        if ([] === $deleted) {
            return 0;
        }

        $owner = $account->getOwner()->getId();
        $seq = $this->sequencer->next($owner);
        $this->sequencer->tombstones($owner, Message::changeType(), array_column($deleted, 'id'), $seq);

        $threads = '{'.implode(',', array_unique(array_column($deleted, 'thread_id'))).'}';
        /** @var list<string> $emptyThreads */
        $emptyThreads = $this->connection->fetchFirstColumn(
            'DELETE FROM thread t
             WHERE t.id = ANY(CAST(:threads AS UUID[])) AND NOT EXISTS (SELECT 1 FROM message m WHERE m.thread_id = t.id)
             RETURNING t.id',
            ['threads' => $threads],
        );
        $this->sequencer->tombstones($owner, Thread::changeType(), $emptyThreads, $seq);

        $this->connection->executeStatement(
            'UPDATE thread t SET message_count = s.message_count, last_message_at = s.last_message_at, change_seq = :seq
             FROM (SELECT thread_id, COUNT(*) AS message_count, MAX(received_at) AS last_message_at
                   FROM message WHERE thread_id = ANY(CAST(:threads AS UUID[])) GROUP BY thread_id) s
             WHERE t.id = s.thread_id',
            ['threads' => $threads, 'seq' => $seq],
        );

        return \count($deleted);
    }
}
