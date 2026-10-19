<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Entity\DraftAttachment;
use App\Realtime\AfterCommitDispatcher;
use App\Repository\DraftAttachmentRepository;
use App\Storage\ObjectStorage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Uploaded files live in object storage under their SHA-256 and may be shared by drafts, so one is only removed once
 * no draft refers to it. Uploading a file and removing it take the same lock on its key, so a file is never removed
 * between being stored and being referred to.
 */
final readonly class UploadCollector
{
    /** Removal waits a little, so an attachment removed by mistake and added again right after is not stored twice. */
    private const int DELAY_MS = 600_000;

    public function __construct(
        private Connection $connection,
        private DraftAttachmentRepository $attachments,
        private ObjectStorage $storage,
        private AfterCommitDispatcher $afterCommit,
    ) {
    }

    /**
     * Holds the key until the transaction ends. Call it inside the transaction that stores or removes the file.
     */
    public function lock(string $objectKey): void
    {
        $this->connection->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => $objectKey]);
    }

    /**
     * The attachments are going (with their draft, or one by one): their uploads are checked once the transaction is
     * in.
     *
     * @param list<DraftAttachment> $attachments
     */
    public function releaseAll(array $attachments): void
    {
        foreach ($attachments as $attachment) {
            if ($attachment->isUpload()) {
                $this->afterCommit->dispatch(new CollectUpload($attachment->getObjectKey()), [new DelayStamp(self::DELAY_MS)]);
            }
        }
    }

    /** Removes the upload when no draft refers to it any more. */
    public function collect(string $objectKey): void
    {
        if (!str_starts_with($objectKey, DraftAttachment::UPLOAD_PREFIX)) {
            throw new \LogicException('Only uploads are collected; received attachments stay.');
        }
        $this->connection->transactional(function () use ($objectKey): void {
            $this->lock($objectKey);
            if (!$this->attachments->isReferenced($objectKey)) {
                $this->storage->delete($objectKey);
            }
        });
    }
}
