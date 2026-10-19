<?php

declare(strict_types=1);

namespace App\ApiResource\Output;

use App\Entity\DraftAttachment;
use App\Enum\ScanStatus;

/**
 * A file attached to a draft, as clients see it. `url` downloads it (signed, for an hour); null while quarantined.
 * `draftVersion` is the draft's version with this file, when the answer comes from adding it.
 */
final readonly class DraftAttachmentOutput
{
    public function __construct(
        public string $id,
        public string $filename,
        public string $contentType,
        public int $size,
        public ScanStatus $scanStatus,
        public ?string $url,
        public bool $inline = false,
        public ?string $contentId = null,
        public ?int $draftVersion = null,
    ) {
    }

    public static function fromEntity(DraftAttachment $attachment, ?string $url, ?int $draftVersion = null): self
    {
        return new self(
            $attachment->getId()->toRfc4122(),
            $attachment->getFilename(),
            $attachment->getContentType(),
            $attachment->getSize(),
            $attachment->getScanStatus(),
            $url,
            $attachment->isInline(),
            $attachment->getContentId(),
            $draftVersion,
        );
    }
}
