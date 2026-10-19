<?php

declare(strict_types=1);

namespace App\Classification;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Per-user advisory locks, held until the transaction ends. Paths that need both take them in this order, smart
 * inboxes first, so they never wait on each other in a circle.
 */
final readonly class UserLocks
{
    public function __construct(private Connection $connection)
    {
    }

    /** Writes to the user's smart inboxes (their lists, names and count) take turns. */
    public function smartInboxes(Uuid $userId): void
    {
        $this->lock('smart-inboxes:'.$userId->toRfc4122());
    }

    /** Classification runs and corrections of the user take turns. */
    public function classification(Uuid $userId): void
    {
        $this->lock('classify:'.$userId->toRfc4122());
    }

    private function lock(string $key): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('User locks are held until the transaction ends; take them inside one.');
        }
        $this->connection->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => $key]);
    }
}
