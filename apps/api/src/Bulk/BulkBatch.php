<?php

declare(strict_types=1);

namespace App\Bulk;

use Symfony\Component\Uid\Uuid;

/** The conversations of one batch, and where the next one starts. */
final readonly class BulkBatch
{
    /**
     * @param list<string> $threadIds the conversations to act on, maybe none when the window held no match
     * @param Uuid|null    $account   the account the next batch looks at; null for a smart inbox, which spans them
     * @param Uuid|null    $after     the last conversation looked at in that account (or smart inbox)
     * @param bool         $done      whether every conversation of the scope was looked at
     */
    public function __construct(
        public array $threadIds,
        public ?Uuid $account,
        public ?Uuid $after,
        public bool $done,
    ) {
    }
}
