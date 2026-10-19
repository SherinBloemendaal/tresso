<?php

declare(strict_types=1);

namespace App\Realtime\Doctrine;

use App\Realtime\ChangeNotifier;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;

final class TransactionTrackingConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $connection, private readonly ChangeNotifier $notifier)
    {
        parent::__construct($connection);
    }

    public function commit(): void
    {
        parent::commit();
        $this->notifier->transactionCommitted();
    }

    public function rollBack(): void
    {
        try {
            parent::rollBack();
        } finally {
            $this->notifier->transactionRolledBack();
        }
    }
}
