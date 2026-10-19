<?php

declare(strict_types=1);

namespace App\Realtime\Doctrine;

use App\Realtime\TransactionListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Reports the outcome of database transactions to every {@see TransactionListener}. The DBAL connection turns nested
 * transactions into savepoints, so the driver only sees the outermost begin, commit and rollback.
 */
#[AsMiddleware]
final readonly class TransactionTrackingMiddleware implements Middleware
{
    /**
     * @param iterable<TransactionListener> $listeners
     */
    public function __construct(
        #[AutowireIterator(TransactionListener::TAG)]
        private iterable $listeners,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        return new TransactionTrackingDriver($driver, $this->listeners);
    }
}
