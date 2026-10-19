<?php

declare(strict_types=1);

namespace App\Classification;

use App\Realtime\AfterCommitDispatcher;
use App\Realtime\TransactionListener;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Queues classification once the transaction that stored new mail or changed the user's smart inboxes has
 * committed, at most once per user and kind per transaction.
 */
final class ClassificationScheduler implements TransactionListener, ResetInterface
{
    /** How far back a change to the smart inboxes or a correction reaches. */
    public const string RECLASSIFY_PERIOD = '-30 days';

    /** @var array<string, true> */
    private array $scheduled = [];

    public function __construct(private readonly AfterCommitDispatcher $afterCommit)
    {
    }

    /** New messages were stored for the user. */
    public function mailArrived(Uuid $userId): void
    {
        $key = 'new:'.$userId->toRfc4122();
        if (!isset($this->scheduled[$key])) {
            $this->afterCommit->dispatch(new ClassifyNewMail($userId->toRfc4122()));
            $this->scheduled[$key] = true;
        }
    }

    /** The user's smart inboxes or remembered corrections changed: recent conversations are classified again. */
    public function rulesChanged(Uuid $userId): void
    {
        $key = 'rerun:'.$userId->toRfc4122();
        if (!isset($this->scheduled[$key])) {
            $since = new \DateTimeImmutable(self::RECLASSIFY_PERIOD)->format(\DATE_ATOM);
            $this->afterCommit->dispatch(new ReclassifyMail($userId->toRfc4122(), $since));
            $this->scheduled[$key] = true;
        }
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
