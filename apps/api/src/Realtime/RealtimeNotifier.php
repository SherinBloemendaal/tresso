<?php

declare(strict_types=1);

namespace App\Realtime;

use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Collects realtime notifications and holds them until the transaction that caused them has committed: a client that
 * fetches the delta, or a worker that claims outgoing mail, must find the data there. Two kinds:
 *
 * - a user's changes reached a new cursor (to that user's clients);
 * - an account has mail to send (to the sync workers).
 *
 * Committed notifications are published once the request, command or message is finished.
 */
final class RealtimeNotifier implements TransactionListener, ResetInterface
{
    /** @var array<string, int> highest change number per user id, in the open transaction */
    private array $pendingCursors = [];

    /** @var array<string, int> highest committed change number per user id, not yet published */
    private array $committedCursors = [];

    /** @var array<string, true> mail account ids with mail to send, in the open transaction */
    private array $pendingOutbox = [];

    /** @var array<string, true> */
    private array $committedOutbox = [];

    public function __construct(private readonly MercurePublisher $publisher)
    {
    }

    public function changed(Uuid $userId, int $seq): void
    {
        $key = $userId->toRfc4122();
        $this->pendingCursors[$key] = max($seq, $this->pendingCursors[$key] ?? 0);
    }

    public function outboxDue(Uuid $mailAccountId): void
    {
        $this->pendingOutbox[$mailAccountId->toRfc4122()] = true;
    }

    public function transactionCommitted(): void
    {
        foreach ($this->pendingCursors as $userId => $seq) {
            $this->committedCursors[$userId] = max($seq, $this->committedCursors[$userId] ?? 0);
        }
        $this->committedOutbox += $this->pendingOutbox;
        $this->pendingCursors = [];
        $this->pendingOutbox = [];
    }

    public function transactionRolledBack(): void
    {
        $this->pendingCursors = [];
        $this->pendingOutbox = [];
    }

    public function publish(): void
    {
        $cursors = $this->committedCursors;
        $outbox = array_keys($this->committedOutbox);
        $this->committedCursors = [];
        $this->committedOutbox = [];

        if ([] !== $cursors) {
            $this->publisher->publishCursors($cursors);
        }
        if ([] !== $outbox) {
            $this->publisher->publishOutbox($outbox);
        }
    }

    public function reset(): void
    {
        $this->pendingCursors = [];
        $this->committedCursors = [];
        $this->pendingOutbox = [];
        $this->committedOutbox = [];
    }
}
