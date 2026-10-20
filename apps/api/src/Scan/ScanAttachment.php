<?php

declare(strict_types=1);

namespace App\Scan;

/**
 * Asks for an attachment's content to be scanned. The verdict applies to every attachment with the same content,
 * received or attached to a draft.
 *
 * A first scan takes a cached verdict of the current signatures when there is one, and does nothing once the
 * attachment is clean or infected. A rescan always asks the scanner and may change any verdict, also back from
 * infected to clean.
 */
final readonly class ScanAttachment
{
    public function __construct(
        public string $attachmentId,
        public ScanTarget $target = ScanTarget::Received,
        public bool $rescan = false,
    ) {
    }
}
