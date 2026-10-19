<?php

declare(strict_types=1);

namespace App\Classification;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Runs classification for the queued messages, a few batches at a time: a long run (a user's first sync, a rule
 * change over a big mailbox) queues the rest as a new message, so other users' work is not stuck behind it.
 */
final readonly class ClassificationHandlers
{
    /** 10 batches of 200: about 2000 messages or conversations per message handled. */
    public const int BATCHES_PER_RUN = 10;

    public function __construct(
        private MailClassification $classification,
        private MessageBusInterface $bus,
        private int $batchesPerRun = self::BATCHES_PER_RUN,
    ) {
    }

    #[AsMessageHandler]
    public function classifyNew(ClassifyNewMail $message): void
    {
        if ($this->classification->classifyNew(Uuid::fromString($message->userId), $this->batchesPerRun)) {
            $this->bus->dispatch(new ClassifyNewMail($message->userId));
        }
    }

    #[AsMessageHandler]
    public function reclassify(ReclassifyMail $message): void
    {
        $next = $this->classification->reclassify(
            Uuid::fromString($message->userId),
            null === $message->since ? null : new \DateTimeImmutable($message->since),
            $message->sender,
            $message->after,
            $this->batchesPerRun,
        );
        if (null !== $next) {
            $this->bus->dispatch(new ReclassifyMail($message->userId, $message->since, $message->sender, $next));
        }
    }
}
