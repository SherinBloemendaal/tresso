<?php

declare(strict_types=1);

namespace App\ApiResource\Output;

/**
 * A piece of text in a search result; `match` marks the pieces that matched the query. Render as text, never as HTML.
 */
final readonly class HighlightSegment
{
    public function __construct(
        public string $text,
        public bool $match,
    ) {
    }
}
