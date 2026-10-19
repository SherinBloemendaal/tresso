<?php

declare(strict_types=1);

namespace App\ApiResource\Output;

/**
 * A message that matched a search. `subject`, `from` (the sender's name, or the address without one) and `preview`
 * are split into segments that mark the matches; `preview` is the passage of the text around the first match, or the
 * snippet when the text did not match. Open the result through `threadId`.
 */
final readonly class SearchHit
{
    /**
     * @param list<string>           $folderIds
     * @param list<HighlightSegment> $subject
     * @param list<HighlightSegment> $from
     * @param list<HighlightSegment> $preview
     * @param list<SearchAttachment> $attachments
     */
    public function __construct(
        public string $id,
        public string $threadId,
        public string $accountId,
        public array $folderIds,
        public string $fromAddress,
        public ?string $fromName,
        public \DateTimeImmutable $receivedAt,
        public bool $seen,
        public bool $flagged,
        public bool $hasAttachments,
        public array $subject,
        public array $from,
        public array $preview,
        public array $attachments,
    ) {
    }
}
