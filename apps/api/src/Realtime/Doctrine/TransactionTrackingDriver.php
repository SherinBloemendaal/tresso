<?php

declare(strict_types=1);

namespace App\Realtime\Doctrine;

use App\Realtime\TransactionListener;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class TransactionTrackingDriver extends AbstractDriverMiddleware
{
    /**
     * @param iterable<TransactionListener> $listeners
     */
    public function __construct(Driver $driver, private readonly iterable $listeners)
    {
        parent::__construct($driver);
    }

    public function connect(array $params): Connection
    {
        return new TransactionTrackingConnection(parent::connect($params), $this->listeners);
    }
}
