<?php

declare(strict_types=1);

namespace App\Realtime\Doctrine;

use App\Realtime\ChangeNotifier;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class TransactionTrackingDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $driver, private readonly ChangeNotifier $notifier)
    {
        parent::__construct($driver);
    }

    public function connect(array $params): Connection
    {
        return new TransactionTrackingConnection(parent::connect($params), $this->notifier);
    }
}
