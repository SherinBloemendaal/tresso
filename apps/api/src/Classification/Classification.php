<?php

declare(strict_types=1);

namespace App\Classification;

use App\Entity\Category;
use App\Enum\Verdict;

/**
 * The outcome for one message: its smart inbox, its verdict, and why (see {@see ClassificationReason}).
 */
final readonly class Classification
{
    public function __construct(
        public Category $smartInbox,
        public Verdict $verdict,
        public string $reason,
    ) {
    }
}
