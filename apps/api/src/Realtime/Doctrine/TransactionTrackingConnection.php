<?php

declare(strict_types=1);

namespace App\Realtime\Doctrine;

use App\Realtime\TransactionListener;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;

final class TransactionTrackingConnection extends AbstractConnectionMiddleware
{
    /**
     * @param iterable<TransactionListener> $listeners
     */
    public function __construct(Connection $connection, private readonly iterable $listeners)
    {
        parent::__construct($connection);
    }

    public function commit(): void
    {
        parent::commit();
        foreach ($this->listeners as $listener) {
            $listener->transactionCommitted();
        }
    }

    public function rollBack(): void
    {
        try {
            parent::rollBack();
        } finally {
            foreach ($this->listeners as $listener) {
                $listener->transactionRolledBack();
            }
        }
    }
}
