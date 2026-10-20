<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scan;

use App\Enum\ScanStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScanStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{list<ScanStatus>, ?ScanStatus}>
     */
    public static function summaries(): iterable
    {
        yield 'no attachments' => [[], null];
        yield 'all clean' => [[ScanStatus::Clean, ScanStatus::Clean], ScanStatus::Clean];
        yield 'infected wins' => [[ScanStatus::Clean, ScanStatus::Pending, ScanStatus::Infected, ScanStatus::Failed], ScanStatus::Infected];
        yield 'pending before failed' => [[ScanStatus::Failed, ScanStatus::Pending, ScanStatus::Clean], ScanStatus::Pending];
        yield 'failed before clean' => [[ScanStatus::Clean, ScanStatus::Failed], ScanStatus::Failed];
    }

    /**
     * @param list<ScanStatus> $statuses
     */
    #[DataProvider('summaries')]
    public function testSummarize(array $statuses, ?ScanStatus $expected): void
    {
        self::assertSame($expected, ScanStatus::summarize($statuses));
    }
}
