<?php

declare(strict_types=1);

namespace App\Realtime\Doctrine;

use App\Realtime\ChangeNotifier;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Reports the outcome of database transactions to the {@see ChangeNotifier}. The DBAL connection turns nested
 * transactions into savepoints, so the driver only sees the outermost begin, commit and rollback.
 */
#[AsMiddleware]
final readonly class TransactionTrackingMiddleware implements Middleware
{
    public function __construct(private ChangeNotifier $notifier)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new TransactionTrackingDriver($driver, $this->notifier);
    }
}
