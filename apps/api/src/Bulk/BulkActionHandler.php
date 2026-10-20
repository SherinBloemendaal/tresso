<?php

declare(strict_types=1);

namespace App\Bulk;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Runs a bulk action a few batches at a time: a long one queues the rest as a new message, so other users' bulk
 * actions are not stuck behind it, and one whose mail servers are behind waits a while. A run that still fails after
 * its retries marks the action as stopped.
 */
final readonly class BulkActionHandler
{
    /** At most 10 batches (up to 2000 conversations or about 5000 messages) and about 10 seconds per message handled. */
    public const int BATCHES_PER_RUN = 10;
    public const float SECONDS_PER_RUN = 10.0;

    /** How long a run waits while the mail servers catch up with the user's changes, in milliseconds. */
    public const int WAIT_MS = 30_000;

    public function __construct(
        private BulkActions $bulkActions,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
        private int $batchesPerRun = self::BATCHES_PER_RUN,
        private float $secondsPerRun = self::SECONDS_PER_RUN,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(RunBulkAction $message): void
    {
        match ($this->bulkActions->run(Uuid::fromString($message->bulkActionId), $this->batchesPerRun, $this->secondsPerRun)) {
            BulkRun::More => $this->bus->dispatch(new RunBulkAction($message->bulkActionId)),
            BulkRun::Wait => $this->bus->dispatch(new RunBulkAction($message->bulkActionId), [new DelayStamp(self::WAIT_MS)]),
            BulkRun::Done => null,
        };
    }

    #[AsEventListener]
    public function onFailure(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof RunBulkAction || $event->willRetry()) {
            return;
        }
        // Never throws: an exception here would stop the worker, which also sends mail, and bring the message back.
        try {
            $this->bulkActions->fail(Uuid::fromString($message->bulkActionId), 'Something went wrong. What was done so far stays done.');
        } catch (\Throwable $error) {
            $this->logger->error('A failed bulk action could not be marked as stopped.', ['bulkActionId' => $message->bulkActionId, 'exception' => $error]);
        }
    }
}
