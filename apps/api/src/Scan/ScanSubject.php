<?php

declare(strict_types=1);

namespace App\Scan;

use App\Entity\Attachment;
use App\Entity\DraftAttachment;
use App\Enum\ScanStatus;

/**
 * What a scan job needs of the attachment it names: its content, how large it is, its current status, and where the
 * content may be stored (its own key, then quarantine).
 */
final readonly class ScanSubject
{
    /**
     * @param list<string> $keys
     */
    private function __construct(
        public string $sha256,
        public int $size,
        public ScanStatus $status,
        public array $keys,
    ) {
    }

    public static function ofAttachment(Attachment $attachment): self
    {
        return new self($attachment->getSha256(), $attachment->getSize(), $attachment->getScanStatus(), [$attachment->getObjectKey(), $attachment->getQuarantineKey()]);
    }

    public static function ofDraftAttachment(DraftAttachment $attachment): self
    {
        return new self($attachment->getSha256(), $attachment->getSize(), $attachment->getScanStatus(), [$attachment->getObjectKey(), Attachment::quarantineKey($attachment->getSha256())]);
    }
}
