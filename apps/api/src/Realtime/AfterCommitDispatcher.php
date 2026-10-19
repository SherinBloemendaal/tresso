<?php

declare(strict_types=1);

namespace App\Realtime;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Dispatches Messenger messages only once the surrounding transaction has committed, so a handler never runs before
 * the data it needs is visible, and never runs at all for work that was rolled back.
 */
final class AfterCommitDispatcher implements TransactionListener, ResetInterface
{
    /** @var list<array{object, list<StampInterface>}> */
    private array $pending = [];

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function dispatch(object $message, array $stamps = []): void
    {
        $connection = $this->doctrine->getConnection();
        if (!$connection instanceof Connection || !$connection->isTransactionActive()) {
            throw new \LogicException('Messages for after the commit must be dispatched inside a transaction.');
        }
        $this->pending[] = [$message, $stamps];
    }

    public function transactionCommitted(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as [$message, $stamps]) {
            $this->bus->dispatch($message, $stamps);
        }
    }

    public function transactionRolledBack(): void
    {
        $this->pending = [];
    }

    public function reset(): void
    {
        $this->pending = [];
    }
}
