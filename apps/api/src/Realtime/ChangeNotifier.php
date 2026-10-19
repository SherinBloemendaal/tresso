<?php

declare(strict_types=1);

namespace App\Realtime;

use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Tells a user's clients that their changes reached a new cursor, but only once the transaction that wrote them has
 * committed: a client that fetches the delta on a notification must find the changes there. Allocated change numbers
 * wait in "pending" until the database transaction ends ({@see Doctrine\TransactionTrackingMiddleware}); committed
 * ones are published once the request, command or message is finished.
 */
final class ChangeNotifier implements ResetInterface
{
    /** @var array<string, int> highest change number per user id, in the open transaction */
    private array $pending = [];

    /** @var array<string, int> highest committed change number per user id, not yet published */
    private array $committed = [];

    public function __construct(private readonly MercurePublisher $publisher)
    {
    }

    public function changed(Uuid $userId, int $seq): void
    {
        $key = $userId->toRfc4122();
        $this->pending[$key] = max($seq, $this->pending[$key] ?? 0);
    }

    public function transactionCommitted(): void
    {
        foreach ($this->pending as $userId => $seq) {
            $this->committed[$userId] = max($seq, $this->committed[$userId] ?? 0);
        }
        $this->pending = [];
    }

    public function transactionRolledBack(): void
    {
        $this->pending = [];
    }

    public function publish(): void
    {
        if ([] === $this->committed) {
            return;
        }
        $committed = $this->committed;
        $this->committed = [];
        $this->publisher->publishCursors($committed);
    }

    public function reset(): void
    {
        $this->pending = [];
        $this->committed = [];
    }
}
