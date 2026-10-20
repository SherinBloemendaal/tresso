<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\HealthController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HealthScannerTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, bool, bool, string}>
     */
    public static function states(): iterable
    {
        yield 'scanning' => [true, false, true, 'ok'];
        yield 'clamd down' => [true, true, false, 'unreachable'];
        yield 'turned off, overrides allowed' => [false, false, true, 'off'];
        yield 'scans required without a scanner' => [false, true, true, 'unconfigured'];
    }

    #[DataProvider('states')]
    public function testScannerState(bool $enabled, bool $required, bool $reachable, string $state): void
    {
        self::assertSame($state, HealthController::scannerState($enabled, $required, static fn (): bool => $reachable));
    }
}
