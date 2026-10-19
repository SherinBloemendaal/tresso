<?php

declare(strict_types=1);

namespace App\Classification;

/**
 * An entry of a smart inbox's include or exclude list, or the key of a sender rule: one address
 * (`anna@example.com`) or a whole domain (`@example.com`, which also covers its subdomains).
 */
final class SenderPattern
{
    /** An address, or `@` and a domain with at least one dot. */
    public const string REGEX = '/^[^@\s]*@[^@\s.]+(\.[^@\s.]+)+$/';

    public static function normalize(string $pattern): string
    {
        return mb_strtolower(trim($pattern));
    }

    public static function isDomain(string $pattern): bool
    {
        return str_starts_with($pattern, '@');
    }

    public static function matches(string $pattern, string $address): bool
    {
        $pattern = self::normalize($pattern);
        $address = mb_strtolower($address);
        if (!self::isDomain($pattern)) {
            return $pattern === $address;
        }

        $domain = self::domainOf($address);

        return $domain === substr($pattern, 1) || str_ends_with($domain, '.'.substr($pattern, 1));
    }

    public static function domainOf(string $address): string
    {
        $at = strrpos($address, '@');

        return false === $at ? '' : mb_strtolower(substr($address, $at + 1));
    }

    public static function localPartOf(string $address): string
    {
        $at = strrpos($address, '@');

        return mb_strtolower(false === $at ? $address : substr($address, 0, $at));
    }

    /**
     * The keys a sender rule for this address can have, most specific first: the address, its domain, then every
     * parent domain (`@mail.example.com`, `@example.com`).
     *
     * @return list<string>
     */
    public static function lookupKeys(string $address): array
    {
        $address = mb_strtolower($address);
        $keys = [$address];
        $labels = explode('.', self::domainOf($address));
        while (\count($labels) >= 2) {
            $keys[] = '@'.implode('.', $labels);
            array_shift($labels);
        }

        return $keys;
    }
}
