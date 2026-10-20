<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scan;

use App\Enum\ScanFailure;
use App\Enum\ScanStatus;
use App\Scan\Clamd\ClamdScanner;
use App\Scan\ScanError;
use App\Scan\ScannerUnavailable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The parts of the clamd conversation a real clamd in CI does not show by default, against a scripted stand-in.
 */
final class ClamdProtocolTest extends TestCase
{
    private ?Process $clamd = null;

    protected function tearDown(): void
    {
        $this->clamd?->stop(0);
    }

    public function testStreamPastClamdsOwnLimitIsTooLarge(): void
    {
        $scanner = $this->scanner('limit', '100000');

        $outcome = $scanner->scan((static function (): iterable {
            for ($i = 0; $i < 64; ++$i) {
                yield str_repeat('x', 65536);
            }
        })());

        self::assertSame(ScanStatus::Failed, $outcome->status);
        self::assertSame(ScanFailure::TooLarge, $outcome->failure);
    }

    public function testErrorReplyIsRetried(): void
    {
        $scanner = $this->scanner('reply', "stream: Can't allocate memory ERROR");

        $this->expectException(ScanError::class);

        $scanner->scan(['content']);
    }

    public function testClamdWithoutSignaturesIsUnavailable(): void
    {
        $scanner = $this->scanner('reply', 'ClamAV 1.5.4');

        $this->expectException(ScannerUnavailable::class);

        $scanner->engine();
    }

    private function scanner(string $mode, string $argument): ClamdScanner
    {
        $this->clamd = new Process([\PHP_BINARY, __DIR__.'/../../Support/bin/scripted-clamd.php', $mode, $argument]);
        $this->clamd->start();
        $port = null;
        $this->clamd->waitUntil(static function (string $type, string $output) use (&$port): bool {
            $port = (int) trim($output);

            return Process::OUT === $type && $port > 0;
        });
        self::assertIsInt($port);

        return new ClamdScanner('127.0.0.1', $port, 26_214_400, 5.0, 10.0);
    }
}
