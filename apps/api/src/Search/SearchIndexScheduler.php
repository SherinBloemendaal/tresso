<?php

declare(strict_types=1);

namespace App\Search;

use App\Realtime\AfterCommitDispatcher;
use App\Realtime\TransactionListener;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Queues indexing for a user whose synced data changed, once that change is committed. {@see
 * \App\Sync\Change\ChangeSequencer} reports every change number here, so one transaction can report a user many times;
 * it is queued once per transaction.
 */
final class SearchIndexScheduler implements TransactionListener, ResetInterface
{
    /** @var array<string, true> user ids queued in the open transaction */
    private array $scheduled = [];

    public function __construct(private readonly AfterCommitDispatcher $afterCommit)
    {
    }

    public function changed(Uuid $userId): void
    {
        $key = $userId->toRfc4122();
        if (isset($this->scheduled[$key])) {
            return;
        }
        $this->afterCommit->dispatch(new IndexUserMail($key));
        $this->scheduled[$key] = true;
    }

    public function transactionCommitted(): void
    {
        $this->scheduled = [];
    }

    public function transactionRolledBack(): void
    {
        $this->scheduled = [];
    }

    public function reset(): void
    {
        $this->scheduled = [];
    }
}
