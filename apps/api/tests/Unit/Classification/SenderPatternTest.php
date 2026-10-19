<?php

declare(strict_types=1);

namespace App\Tests\Unit\Classification;

use App\Classification\SenderPattern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SenderPatternTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function pairs(): iterable
    {
        yield 'same address' => ['anna@example.com', 'Anna@Example.com', true];
        yield 'other address' => ['anna@example.com', 'bob@example.com', false];
        yield 'domain' => ['@example.com', 'bob@example.com', true];
        yield 'subdomain' => ['@example.com', 'bob@mail.example.com', true];
        yield 'domain that only ends the same' => ['@example.com', 'bob@badexample.com', false];
        yield 'padded upper-case pattern' => ['  @EXAMPLE.com ', 'bob@example.com', true];
    }

    #[DataProvider('pairs')]
    public function testMatches(string $pattern, string $address, bool $expected): void
    {
        self::assertSame($expected, SenderPattern::matches($pattern, $address));
    }

    public function testLookupKeysGoFromTheAddressToTheWidestDomain(): void
    {
        self::assertSame(
            ['bob@mail.example.com', '@mail.example.com', '@example.com'],
            SenderPattern::lookupKeys('Bob@Mail.Example.com'),
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function patterns(): iterable
    {
        yield 'address' => ['anna@example.com', true];
        yield 'domain' => ['@example.com', true];
        yield 'bare domain' => ['example.com', false];
        yield 'no dot' => ['@localhost', false];
        yield 'two at signs' => ['a@b@example.com', false];
        yield 'spaces' => ['an na@example.com', false];
    }

    #[DataProvider('patterns')]
    public function testRegex(string $pattern, bool $valid): void
    {
        self::assertSame($valid, 1 === preg_match(SenderPattern::REGEX, $pattern));
    }
}
