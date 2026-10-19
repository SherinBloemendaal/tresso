<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\BuiltinCategory;
use App\Enum\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BuiltinCategoryTest extends TestCase
{
    /** @return iterable<string, array{BuiltinCategory, Verdict}> */
    public static function verdicts(): iterable
    {
        yield 'todo is something to do' => [BuiltinCategory::Todo, Verdict::Do];
        yield 'informational is something to know' => [BuiltinCategory::Informational, Verdict::Know];
        yield 'notifications are something to know' => [BuiltinCategory::Notification, Verdict::Know];
        yield 'advertising is something to know' => [BuiltinCategory::Advertise, Verdict::Know];
        yield 'spam needs no verdict' => [BuiltinCategory::Spam, Verdict::None];
    }

    #[DataProvider('verdicts')]
    public function testDefaultVerdict(BuiltinCategory $category, Verdict $expected): void
    {
        self::assertSame($expected, $category->defaultVerdict());
    }
}
