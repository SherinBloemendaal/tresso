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

    /** The user's smart inboxes changed: every recent conversation is classified again. */
    public function rulesChanged(Uuid $userId): void
    {
        $this->rerun($userId, null);
    }

    /**
     * A remembered correction for one sender (an address or `@domain`) was made or forgotten: only recent
     * conversations with mail from that sender are classified again.
     */
    public function senderChanged(Uuid $userId, string $sender): void
    {
        $this->rerun($userId, SenderPattern::normalize($sender));
    }

    private function rerun(Uuid $userId, ?string $sender): void
    {
        $key = 'rerun:'.$userId->toRfc4122().':'.($sender ?? '*');
        if (isset($this->scheduled['rerun:'.$userId->toRfc4122().':*']) || isset($this->scheduled[$key])) {
            return;
        }
        $since = new \DateTimeImmutable(self::RECLASSIFY_PERIOD)->format(\DATE_ATOM);
        $this->afterCommit->dispatch(new ReclassifyMail($userId->toRfc4122(), $since, $sender));
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
