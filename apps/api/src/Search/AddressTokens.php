<?php

declare(strict_types=1);

namespace App\Search;

/**
 * The words by which `from:` and `to:` find an address, so "from:anna", "from:finance.example",
 * "from:anna@finance.example" and "from:"Anna de Vries"" all match Anna de Vries <anna@finance.example>.
 *
 * Indexed per address: the address, its local part and the pieces of that, its domain and each parent domain, and the
 * words of the display name. A query value is turned into tokens the same way, and every query token must be among
 * the indexed ones.
 */
final class AddressTokens
{
    /**
     * @return list<string>
     */
    public static function forAddress(string $address, ?string $name): array
    {
        $address = mb_strtolower(trim($address));
        $tokens = [$address];

        $at = strrpos($address, '@');
        $local = false === $at ? $address : substr($address, 0, $at);
        $domain = false === $at ? '' : substr($address, $at + 1);

        if ('' !== $local) {
            $tokens = [...$tokens, $local, ...self::words($local)];
        }
        $labels = '' === $domain ? [] : explode('.', $domain);
        for ($i = 0; $i < \count($labels) - 1; ++$i) {
            $tokens[] = implode('.', \array_slice($labels, $i));
        }
        if (null !== $name) {
            $tokens = [...$tokens, ...self::words($name)];
        }

        return array_values(array_unique(array_filter($tokens, static fn (string $token): bool => '' !== $token)));
    }

    /**
     * What a `from:` or `to:` value must match: a single address, domain or local part as a whole, or the words of a
     * name. A leading @ ("from:@finance.example") is dropped.
     *
     * @return list<string>
     */
    public static function forQuery(string $value): array
    {
        $value = mb_strtolower(trim($value));
        if (1 === preg_match('/\s/u', $value)) {
            return self::words($value);
        }
        $value = ltrim($value, '@');

        return '' === $value ? [] : [$value];
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), flags: \PREG_SPLIT_NO_EMPTY);

        return false === $words ? [] : $words;
    }
}
