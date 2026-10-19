<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Reduces a subject to what stays the same across a conversation: no reply or forward prefixes (in the languages
 * Tresso's users write in), no mailing list tags, lowercase, single spaces.
 */
final class SubjectNormalizer
{
    private const string PREFIXES = '(?:re|fw|fwd|aw|wg|sv|vs|antw|doorst|tr|rif|i|r)';

    public static function normalize(string $subject): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $subject) ?? $subject);

        do {
            $before = $normalized;
            // "Re:", "RE[2]:", "Fwd :", "[list-name]" at the start.
            $normalized = trim(preg_replace('/^(?:'.self::PREFIXES.'\s*(?:\[\d+\])?\s*:|\[[^\]]{1,40}\])\s*/iu', '', $normalized) ?? $normalized);
        } while ($normalized !== $before);

        return mb_substr(mb_strtolower($normalized), 0, 255);
    }
}
