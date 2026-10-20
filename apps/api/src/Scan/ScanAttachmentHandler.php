<?php

declare(strict_types=1);

namespace App\Scan;

use App\Enum\ScanFailure;
use App\Enum\ScanStatus;
use App\Storage\BlobVault;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Scans attachment content with clamd, or takes the cached verdict of the same signatures. Encrypted content is
 * decrypted on the way to clamd and never written anywhere in plaintext. Exceptions (scanner down or answering with an
 * error, content not in object storage) make Messenger retry; {@see ScanRetriesExhaustedListener} records the failure
 * once it gives up.
 */
#[AsMessageHandler]
final readonly class ScanAttachmentHandler
{
    public function __construct(
        private ScanSubjects $subjects,
        private ScanCache $cache,
        private VirusScanner $scanner,
        private BlobVault $blobs,
        private AttachmentScans $scans,
        private LoggerInterface $logger,
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
            $this->scans->record($subject->sha256, ScanOutcome::failed(ScanFailure::Disabled), false);

            return;
        }

        // Also proves clamd has signatures loaded: it refuses to report a version without.
        $engine = $this->scanner->engine();

        try {
            $cacheKey = $this->cache->keyFor($subject);
        } catch (ContentMismatch $exception) {
            $this->logger->error('An attachment is not what its fingerprint says; it is not scanned and not served without confirmation.', ['attachment' => $message->attachmentId, 'exception' => $exception]);
            $this->scans->record($subject->sha256, ScanOutcome::failed(ScanFailure::Unscannable), false);

            return;
        }
        $cached = $message->rescan ? null : $this->cache->find($cacheKey);
        if (null !== $cached && $cached->getEngine() === $engine) {
            $this->scans->record($subject->sha256, $cached->outcome(), false);

            return;
        }

        if ($subject->size > $this->scanner->maxSize()) {
            $this->scans->record($subject->sha256, ScanOutcome::failed(ScanFailure::TooLarge), $message->rescan);

            return;
        }

        $outcome = $this->scanner->scan(ScanContent::stream($this->blobs, $subject));
        $this->scans->record($subject->sha256, $outcome, $message->rescan, $cacheKey, $engine);
    }
}
