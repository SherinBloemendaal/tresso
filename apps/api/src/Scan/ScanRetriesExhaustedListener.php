<?php

declare(strict_types=1);

namespace App\Scan;

use App\Enum\ScanFailure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * Once Messenger gives up on a scan, the attachment is marked failed instead of staying pending forever: unscannable
 * when clamd kept answering with an error, unavailable otherwise. Clean and infected verdicts are kept: a rescan that
 * cannot finish changes nothing. It runs before {@see \App\Realtime\PublishChangesSubscriber}, so clients hear about
 * the failure right away.
 */
#[AsEventListener(priority: 10)]
final readonly class ScanRetriesExhaustedListener
{
    public function __construct(
        private ScanSubjects $subjects,
        private AttachmentScans $scans,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $doctrine,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof ScanAttachment || $event->willRetry()) {
            return;
        }

        // A failure inside a transaction closes the entity manager.
        if (!$this->entityManager->isOpen()) {
            $this->doctrine->resetManager();
        }

        $subject = $this->subjects->find($message);
        if (null === $subject) {
            return;
        }

        $throwable = $event->getThrowable();
        $clamdError = $throwable instanceof ScanError
            || ($throwable instanceof HandlerFailedException && [] !== $throwable->getWrappedExceptions(ScanError::class, true));
        $this->logger->error('Gave up scanning attachment {id}: {reason}', ['id' => $message->attachmentId, 'reason' => $throwable->getMessage()]);
        $this->scans->record($subject->sha256, ScanOutcome::failed($clamdError ? ScanFailure::Unscannable : ScanFailure::Unavailable), null, false);
    }
}
