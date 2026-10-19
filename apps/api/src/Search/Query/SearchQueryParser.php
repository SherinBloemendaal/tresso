<?php

declare(strict_types=1);

namespace App\Search\Query;

/**
 * Parses the search box syntax:
 *
 * - words and "quoted phrases" are searched in subject, sender, recipients, attachment names and text; a word with a
 *   leading minus excludes messages that contain it
 * - from:<address, domain or name>, to:<same> (To, Cc and Bcc); quote names with spaces: from:"Anna de Vries"
 * - has:attachment
 * - in:<folder role, account or folder>, e.g. in:inbox, in:archive, in:work@company.example, in:Invoices
 * - after:<date> (received on or after that day) and before:<date> (received before that day), as 2026-10-01 or
 *   2026/10/01, in the given time zone
 *
 * Filter names are case-insensitive. Anything else that looks like name:value (a URL, a time) is a plain word.
 */
final class SearchQueryParser
{
    private const array OPERATORS = ['from', 'to', 'in', 'has', 'after', 'before'];

    public function parse(string $query, \DateTimeZone $timeZone): SearchQuery
    {
        $words = [];
        $from = $to = $in = [];
        $hasAttachment = false;
        $after = $before = null;

        preg_match_all('/(?:[^\s"]+|"[^"]*"?)+/u', $query, $matches);
        foreach ($matches[0] as $token) {
            if (1 !== preg_match('/^([a-z]+):(.*)$/iu', $token, $parts) || !\in_array(strtolower($parts[1]), self::OPERATORS, true)) {
                $words[] = self::balanceQuotes($token);
                continue;
            }

            $value = trim(str_replace('"', '', $parts[2]));
            if ('' === $value) {
                continue;
            }

            switch (strtolower($parts[1])) {
                case 'from':
                    $from[] = $value;
                    break;
                case 'to':
                    $to[] = $value;
                    break;
                case 'in':
                    $in[] = $value;
                    break;
                case 'has':
                    if (!\in_array(strtolower($value), ['attachment', 'attachments'], true)) {
                        throw new InvalidSearchQuery(\sprintf('"has:%s" is not a filter. Use has:attachment.', $value));
                    }
                    $hasAttachment = true;
                    break;
                case 'after':
                    $after = self::day('after', $value, $timeZone);
                    break;
                case 'before':
                    $before = self::day('before', $value, $timeZone);
                    break;
            }
        }

        $text = trim(implode(' ', array_filter($words, static fn (string $word): bool => '' !== $word)));

        return new SearchQuery($text, $from, $to, $in, $hasAttachment, $after, $before);
    }

    private static function day(string $operator, string $value, \DateTimeZone $timeZone): \DateTimeImmutable
    {
        $normalized = str_replace('/', '-', $value);
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $normalized, $timeZone);
        if (false === $day || $day->format('Y-m-d') !== $normalized) {
            throw new InvalidSearchQuery(\sprintf('"%s:%s" needs a date like %s:2026-10-01.', $operator, $value, $operator));
        }

        return $day;
    }

    /**
     * A stray quote would make Meilisearch treat the rest of the query as one phrase: drop the quotes of a word that
     * does not close them.
     */
    private static function balanceQuotes(string $word): string
    {
        return 0 === substr_count($word, '"') % 2 ? $word : str_replace('"', '', $word);
    }
}
