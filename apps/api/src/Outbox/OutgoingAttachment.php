<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Entity\DraftAttachment;

/** A file that goes out with a message, read from object storage when the message is built. */
final readonly class OutgoingAttachment
{
    public function __construct(
        public string $filename,
        public string $contentType,
        public string $objectKey,
        public int $size,
        /** Shown inside the text as cid:{contentId}; null for a file listed with the message. */
        public ?string $contentId = null,
    ) {
    }

    public static function fromDraft(DraftAttachment $attachment): self
    {
        return new self($attachment->getFilename(), $attachment->getContentType(), $attachment->getObjectKey(), $attachment->getSize(), $attachment->isInline() ? $attachment->getContentId() : null);
    }
}
