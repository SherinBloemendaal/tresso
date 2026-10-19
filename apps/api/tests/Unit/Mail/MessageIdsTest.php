<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Sync\Ingest\MessageIds;
use PHPUnit\Framework\TestCase;

final class MessageIdsTest extends TestCase
{
    public function testStripsAngleBracketsAndWhitespace(): void
    {
        self::assertSame('abc@example.test', MessageIds::normalize(' <abc@example.test> '));
        self::assertSame('abc@example.test', MessageIds::normalize('abc@example.test'));
        self::assertSame('CaseSensitive@x', MessageIds::normalize('<CaseSensitive@x>'));
    }

    public function testEmptyBecomesNull(): void
    {
        self::assertNull(MessageIds::normalize(null));
        self::assertNull(MessageIds::normalize('  '));
        self::assertNull(MessageIds::normalize('<>'));
    }

    public function testListIsUniqueAndExcludesTheMessageItself(): void
    {
        self::assertSame(['a@x', 'b@x'], MessageIds::normalizeList(['<a@x>', 'b@x', '<a@x>', null, '<self@x>', ''], 'self@x'));
    }
}
