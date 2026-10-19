<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Search\Query\InvalidSearchQuery;
use App\Search\Query\SearchQuery;
use App\Search\Query\SearchQueryParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchQueryParserTest extends TestCase
{
    public function testPlainWordsAreText(): void
    {
        $query = $this->parse('  Q4   budget ');

        self::assertSame('Q4 budget', $query->text);
        self::assertSame([], $query->from);
        self::assertFalse($query->hasAttachment);
    }

    public function testSeparatesFiltersFromWords(): void
    {
        $query = $this->parse('budget from:anna to:kim@company.example has:attachment in:inbox revised');

        self::assertSame('budget revised', $query->text);
        self::assertSame(['anna'], $query->from);
        self::assertSame(['kim@company.example'], $query->to);
        self::assertSame(['inbox'], $query->in);
        self::assertTrue($query->hasAttachment);
    }

    public function testQuotedFilterValuesKeepTheirSpaces(): void
    {
        $query = $this->parse('from:"Anna de Vries" in:"Work stuff" invoice');

        self::assertSame(['Anna de Vries'], $query->from);
        self::assertSame(['Work stuff'], $query->in);
        self::assertSame('invoice', $query->text);
    }

    public function testPhrasesAndExclusionsStayInTheText(): void
    {
        self::assertSame('"team day" -dinner', $this->parse('"team day" -dinner')->text);
    }

    public function testAnUnclosedQuoteIsDropped(): void
    {
        self::assertSame('budget revised', $this->parse('"budget revised')->text);
    }

    public function testFilterNamesAreCaseInsensitiveAndRepeatable(): void
    {
        $query = $this->parse('FROM:anna From:finance.example HAS:Attachments');

        self::assertSame(['anna', 'finance.example'], $query->from);
        self::assertTrue($query->hasAttachment);
    }

    public function testUnknownNamesAndUrlsArePlainWords(): void
    {
        self::assertSame('https://tresso.example/a meeting:10:30 subject:x', $this->parse('https://tresso.example/a meeting:10:30 subject:x')->text);
    }

    public function testAFilterWithoutValueIsIgnored(): void
    {
        $query = $this->parse('budget from:');

        self::assertSame('budget', $query->text);
        self::assertSame([], $query->from);
    }

    public function testDatesAreDaysInTheGivenTimeZone(): void
    {
        $query = $this->parse('after:2026-10-01 before:2026/10/15', 'Europe/Amsterdam');

        self::assertSame('2026-09-30T22:00:00+00:00', $query->after?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
        self::assertSame('2026-10-14T22:00:00+00:00', $query->before?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
        self::assertSame('', $query->text);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'not a date' => ['before:yesterday', '"before:yesterday" needs a date like before:2026-10-01.'];
        yield 'impossible date' => ['after:2026-02-30', '"after:2026-02-30" needs a date like after:2026-10-01.'];
        yield 'unpadded date' => ['after:2026-1-5', '"after:2026-1-5" needs a date like after:2026-10-01.'];
        yield 'unknown has' => ['has:flag', '"has:flag" is not a filter. Use has:attachment.'];
    }

    #[DataProvider('invalidQueries')]
    public function testRejectsFiltersWrittenWrongly(string $query, string $message): void
    {
        $this->expectException(InvalidSearchQuery::class);
        $this->expectExceptionMessage($message);

        $this->parse($query);
    }

    public function testEmptiness(): void
    {
        self::assertTrue($this->parse('   ')->isEmpty());
        self::assertTrue($this->parse('from:')->isEmpty());
        self::assertFalse($this->parse('has:attachment')->isEmpty());
        self::assertFalse($this->parse('before:2026-10-01')->isEmpty());
    }

    private function parse(string $query, string $timeZone = 'UTC'): SearchQuery
    {
        return new SearchQueryParser()->parse($query, new \DateTimeZone($timeZone));
    }
}
