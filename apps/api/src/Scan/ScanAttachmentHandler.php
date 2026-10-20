<?php

declare(strict_types=1);

namespace App\Scan;

use App\Enum\ScanFailure;
use App\Enum\ScanStatus;
use App\Repository\ScanResultRepository;
use App\Storage\ObjectNotFound;
use App\Storage\ObjectStorage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Scans attachment content with clamd, or takes the cached verdict of the same signatures. Exceptions (scanner down
 * or answering with an error, content not in object storage) make Messenger retry; {@see ScanRetriesExhaustedListener}
 * records the failure once it gives up.
 */
#[AsMessageHandler]
final readonly class ScanAttachmentHandler
{
    public function __construct(
        private ScanSubjects $subjects,
        private ScanResultRepository $results,
        private VirusScanner $scanner,
        private ObjectStorage $storage,
        private AttachmentScans $scans,
    ) {
    }

    public function __invoke(ScanAttachment $message): void
    {
        $subject = $this->subjects->find($message);
        if (null === $subject) {
            return;
        }
        // A failed attachment is scanned again when asked (the sweeper, messenger:failed:retry); a settled one only by
        // a rescan.
        if (!$message->rescan && !\in_array($subject->status, [ScanStatus::Pending, ScanStatus::Failed], true)) {
            return;
        }

        if (!$this->scanner->isEnabled()) {
            $this->scans->record($subject->sha256, ScanOutcome::failed(ScanFailure::Disabled), null, false);

            return;
        }

        // Also proves clamd has signatures loaded: it refuses to report a version without.
        $engine = $this->scanner->engine();

        $cached = $message->rescan ? null : $this->results->find($subject->sha256);
        if (null !== $cached && $cached->getEngine() === $engine) {
            $this->scans->record($subject->sha256, $cached->outcome(), null, false);

            return;
        }

        if ($subject->size > $this->scanner->maxSize()) {
            $this->scans->record($subject->sha256, ScanOutcome::failed(ScanFailure::TooLarge), null, $message->rescan);

            return;
        }

        $outcome = $this->scanner->scan($this->content($subject));
        $this->scans->record($subject->sha256, $outcome, $engine, $message->rescan);
    }

    /**
     * Content that was quarantined before is still scanned there, so a rescan can clear it.
     *
     * @return iterable<string>
     */
    private function content(ScanSubject $subject): iterable
    {
        foreach ($subject->keys as $key) {
            try {
                return $this->storage->stream($key);
            } catch (ObjectNotFound) {
                continue;
            }
        }

        throw ObjectNotFound::forKey($subject->keys[0]);
    }
}
