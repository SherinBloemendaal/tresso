<?php

declare(strict_types=1);

namespace App\Bulk;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Runs a bulk action a few batches at a time: a long one queues the rest as a new message, so other users' bulk
 * actions are not stuck behind it. A run that still fails after its retries marks the action as stopped.
 */
final readonly class BulkActionHandler
{
    /** 10 batches: up to 2000 conversations or about 5000 messages per message handled. */
    public const int BATCHES_PER_RUN = 10;

    public function __construct(
        private BulkActions $bulkActions,
        private MessageBusInterface $bus,
        private int $batchesPerRun = self::BATCHES_PER_RUN,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(RunBulkAction $message): void
    {
        if ($this->bulkActions->run(Uuid::fromString($message->bulkActionId), $this->batchesPerRun)) {
            $this->bus->dispatch(new RunBulkAction($message->bulkActionId));
        }
    }

    #[AsEventListener]
    public function onFailure(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof RunBulkAction || $event->willRetry()) {
            return;
        }
        $this->bulkActions->fail(Uuid::fromString($message->bulkActionId), 'Something went wrong. What was done so far stays done.');
    }
}
