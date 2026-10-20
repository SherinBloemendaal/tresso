<?php

declare(strict_types=1);

namespace App\Scan;

use App\Repository\AttachmentRepository;
use App\Repository\DraftAttachmentRepository;

final readonly class ScanSubjects
{
    public function __construct(
        private AttachmentRepository $attachments,
        private DraftAttachmentRepository $draftAttachments,
    ) {
    }

    /** Null when the attachment is gone (its message or draft was removed in the meantime). */
    public function find(ScanAttachment $message): ?ScanSubject
    {
        if (ScanTarget::Draft === $message->target) {
            $attachment = $this->draftAttachments->findById($message->attachmentId);

            return null === $attachment ? null : ScanSubject::ofDraftAttachment($attachment);
        }
        $attachment = $this->attachments->findById($message->attachmentId);

        return null === $attachment ? null : ScanSubject::ofAttachment($attachment);
    }
}
