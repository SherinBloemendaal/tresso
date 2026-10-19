<?php

declare(strict_types=1);

namespace App\Search;

use App\ApiResource\Output\HighlightSegment;

/**
 * Splits text that Meilisearch marked with {@see MessageIndex::HIGHLIGHT_START} and END into plain segments, so
 * clients render matches as text and never as markup taken from a message.
 */
final class Highlights
{
    /**
     * @return list<HighlightSegment>
     */
    public static function segments(string $marked): array
    {
        $segments = [];
        $match = false;
        foreach (preg_split('/(\x{E000}|\x{E001})/u', $marked, flags: \PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
            if (MessageIndex::HIGHLIGHT_START === $part || MessageIndex::HIGHLIGHT_END === $part) {
                $match = MessageIndex::HIGHLIGHT_START === $part;
                continue;
            }
            if ('' === $part) {
                continue;
            }
            $last = array_pop($segments);
            if (null !== $last && $last->match === $match) {
                $segments[] = new HighlightSegment($last->text.$part, $match);
                continue;
            }
            if (null !== $last) {
                $segments[] = $last;
            }
            $segments[] = new HighlightSegment($part, $match);
        }

        return $segments;
    }

    public static function hasMatch(string $marked): bool
    {
        return str_contains($marked, MessageIndex::HIGHLIGHT_START);
    }
}
