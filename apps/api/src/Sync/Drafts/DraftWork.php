<?php

declare(strict_types=1);

namespace App\Sync\Drafts;

/**
 * What a worker must do in an account's Drafts folder. A file appends the copy at `rawObjectKey` and then removes the
 * copy with `previousMessageId`, if any; a removal removes the copy with `messageId`.
 */
final readonly class DraftWork
{
    /**
     * @param list<array{draftId: string, version: int, rawObjectKey: string, messageId: string, previousMessageId: ?string}> $files
     * @param list<array{id: string, messageId: string}>                                                                     $removals
     */
    public function __construct(
        public array $files,
        public array $removals,
    ) {
    }
}
