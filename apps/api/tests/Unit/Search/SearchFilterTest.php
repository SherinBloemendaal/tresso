<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Search\Query\Scope;
use App\Search\Query\SearchFilter;
use App\Search\Query\SearchQuery;
use PHPUnit\Framework\TestCase;

final class SearchFilterTest extends TestCase
{
    public function testAlwaysFiltersOnTheOwnerAndTheirAccounts(): void
    {
        self::assertSame(
            ['ownerId = "u1"', 'accountId IN ["a1", "a2"]'],
            SearchFilter::build('u1', ['a1', 'a2'], new SearchQuery('budget'), []),
        );
    }

    public function testEveryFilterBecomesAnExpression(): void
    {
        $query = new SearchQuery(
            'budget',
            from: ['Anna de Vries'],
            to: ['@company.example'],
            in: ['inbox', 'work'],
            hasAttachment: true,
            after: new \DateTimeImmutable('2026-10-01T00:00:00Z'),
            before: new \DateTimeImmutable('2026-10-15T00:00:00Z'),
        );

        self::assertSame([
            'ownerId = "u1"',
            'accountId IN ["a1"]',
            'fromTokens = "anna"',
            'fromTokens = "de"',
            'fromTokens = "vries"',
            'toTokens = "company.example"',
            'folderIds IN ["f1", "f2"]',
            'accountId IN ["a1"]',
            'hasAttachments = true',
            'receivedAt >= 1790812800',
            'receivedAt < 1792022400',
        ], SearchFilter::build('u1', ['a1'], $query, [Scope::folders(['f1', 'f2']), Scope::accounts(['a1'])]));
    }

    public function testTypedValuesCannotBreakOutOfTheirQuotes(): void
    {
        $filters = SearchFilter::build('u1', ['a1'], new SearchQuery('', from: ['x"OR(ownerId!="u1")', 'a\\"b']), []);

        self::assertSame('fromTokens = "x\\"or(ownerid!=\\"u1\\")"', $filters[2]);
        self::assertSame('fromTokens = "a\\\\\\"b"', $filters[3]);
    }
}
