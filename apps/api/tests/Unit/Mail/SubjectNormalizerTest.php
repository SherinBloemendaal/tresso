<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Mail\SubjectNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubjectNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function subjects(): iterable
    {
        yield 'plain' => ['Q4 budget', 'q4 budget'];
        yield 'reply' => ['Re: Q4 budget', 'q4 budget'];
        yield 'nested reply and forward' => ['RE: Fwd: re: Q4 budget', 'q4 budget'];
        yield 'numbered reply' => ['Re[2]: Q4 budget', 'q4 budget'];
        yield 'space before colon' => ['Fwd : Q4 budget', 'q4 budget'];
        yield 'dutch' => ['Antw: Doorst: Q4 budget', 'q4 budget'];
        yield 'german' => ['AW: WG: Q4 budget', 'q4 budget'];
        yield 'list tag' => ['[team] Re: Q4 budget', 'q4 budget'];
        yield 'whitespace' => ["  Re:\tQ4   budget ", 'q4 budget'];
        yield 'prefix inside is kept' => ['Notes: Re: Q4 budget', 'notes: re: q4 budget'];
        yield 'only prefixes' => ['Re: Fwd:', ''];
        yield 'unicode' => ['RE: Überweisung März', 'überweisung märz'];
    }

    #[DataProvider('subjects')]
    public function testNormalizes(string $subject, string $expected): void
    {
        self::assertSame($expected, SubjectNormalizer::normalize($subject));
    }

    public function testLimitsLength(): void
    {
        self::assertSame(255, mb_strlen(SubjectNormalizer::normalize(str_repeat('é', 400))));
    }
}
