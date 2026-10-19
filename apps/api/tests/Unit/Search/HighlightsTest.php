<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\ApiResource\Output\HighlightSegment;
use App\Search\Highlights;
use App\Search\MessageIndex;
use PHPUnit\Framework\TestCase;

final class HighlightsTest extends TestCase
{
    public function testSplitsMarkedTextIntoSegments(): void
    {
        $marked = 'Q4 '.MessageIndex::HIGHLIGHT_START.'budget'.MessageIndex::HIGHLIGHT_END.', revised '.MessageIndex::HIGHLIGHT_START.'<b>'.MessageIndex::HIGHLIGHT_END;

        self::assertEquals(
            [new HighlightSegment('Q4 ', false), new HighlightSegment('budget', true), new HighlightSegment(', revised ', false), new HighlightSegment('<b>', true)],
            Highlights::segments($marked),
        );
        self::assertTrue(Highlights::hasMatch($marked));
    }

    public function testAdjacentMatchesMerge(): void
    {
        $marked = MessageIndex::HIGHLIGHT_START.'bud'.MessageIndex::HIGHLIGHT_END.MessageIndex::HIGHLIGHT_START.'get'.MessageIndex::HIGHLIGHT_END;

        self::assertEquals([new HighlightSegment('budget', true)], Highlights::segments($marked));
    }

    public function testTextWithoutMarksIsOneSegment(): void
    {
        self::assertEquals([new HighlightSegment('Hello', false)], Highlights::segments('Hello'));
        self::assertSame([], Highlights::segments(''));
        self::assertFalse(Highlights::hasMatch('Hello'));
    }

    public function testMarkersAreRemovedFromIndexedText(): void
    {
        self::assertSame('ab', MessageIndex::clean('a'.MessageIndex::HIGHLIGHT_START.'b'.MessageIndex::HIGHLIGHT_END));
    }
}
