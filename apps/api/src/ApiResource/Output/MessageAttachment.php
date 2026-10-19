<?php

declare(strict_types=1);

namespace App\ApiResource\Output;

use App\Entity\Attachment;
use App\Enum\ScanStatus;

/**
 * An attachment of a message. `url` is a signed link valid for an hour; it is null for quarantined (infected)
 * attachments. Inline attachments are images the HTML body shows itself.
 */
final readonly class MessageAttachment
{
    public function __construct(
        public string $id,
        public string $filename,
        public string $contentType,
        public int $size,
        public bool $inline,
        public ?string $contentId,
        public ScanStatus $scanStatus,
        public ?string $url,
    ) {
    }

    public static function fromEntity(Attachment $attachment, ?string $url): self
    {
        return new self(
            $attachment->getId()->toRfc4122(),
            $attachment->getFilename(),
            $attachment->getContentType(),
            $attachment->getSize(),
            $attachment->isInline(),
            $attachment->getContentId(),
            $attachment->getScanStatus(),
            $url,
        );
    }
}
