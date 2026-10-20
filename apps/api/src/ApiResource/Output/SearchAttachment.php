<?php

declare(strict_types=1);

namespace App\ApiResource\Output;

use App\Enum\ScanStatus;

/**
 * An attachment of a found message. `matched` says whether its filename matched the query, `filename` then marks
 * where. `scanStatus` is what the virus scan said (null for messages not indexed again since scanning came). Fetch
 * the message body for a download link.
 */
final readonly class SearchAttachment
{
    /**
     * @param list<HighlightSegment> $filename
     */
    public function __construct(
        public string $id,
        public array $filename,
        public string $contentType,
        public int $size,
        public bool $matched,
        public ?ScanStatus $scanStatus,
    ) {
    }
}
