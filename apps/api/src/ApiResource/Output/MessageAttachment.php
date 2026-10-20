<?php

declare(strict_types=1);

namespace App\ApiResource\Output;

use App\Entity\Attachment;
use App\Enum\ScanFailure;
use App\Enum\ScanStatus;
use App\Scan\AttachmentAccess;

/**
 * An attachment of a message. `access` says what the scan allows: `allowed` (clean; `url` is a signed link valid for
 * an hour), `scanning`, `confirm` (could not be scanned; POST /api/v1/attachments/{id}/unscanned-link with the user's
 * confirmation gives a link) or `blocked`. `url` is null unless allowed. `scanSignature` names the malware found;
 * `scanFailure` says why a scan failed. Inline attachments are images the HTML body shows itself.
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
        public AttachmentAccess $access,
        public ?string $scanSignature,
        public ?ScanFailure $scanFailure,
        public ?string $url,
    ) {
    }

    public static function fromEntity(Attachment $attachment, ?string $url, AttachmentAccess $access): self
    {
        return new self(
            $attachment->getId()->toRfc4122(),
            $attachment->getFilename(),
            $attachment->getContentType(),
            $attachment->getSize(),
            $attachment->isInline(),
            $attachment->getContentId(),
            $attachment->getScanStatus(),
            $access,
            $attachment->getScanSignature(),
            $attachment->getScanFailure(),
            $url,
        );
    }
}
