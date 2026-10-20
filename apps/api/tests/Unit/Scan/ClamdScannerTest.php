<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scan;

use App\Enum\ScanFailure;
use App\Enum\ScanStatus;
use App\Scan\Clamd\ClamdScanner;
use App\Scan\ScannerUnavailable;
use App\Tests\Support\FakeVirusScanner;
use PHPUnit\Framework\TestCase;

/**
 * Talks to a real clamd at CLAMAV_HOST:CLAMAV_PORT, configured with docker/clamav/clamd.conf (CI runs one). Without
 * CLAMAV_HOST the clamd tests are skipped locally and fail on CI.
 */
final class ClamdScannerTest extends TestCase
{
    /**
     * The EICAR standard only counts a file that starts with the string, and clamd follows it.
     */
    public function testFindsTheEicarTestFile(): void
    {
        $outcome = $this->scanner()->scan([substr(FakeVirusScanner::EICAR, 0, 30), substr(FakeVirusScanner::EICAR, 30)]);

        self::assertSame(ScanStatus::Infected, $outcome->status);
        self::assertStringContainsStringIgnoringCase('eicar', (string) $outcome->signature);
    }

    public function testCleanContentPasses(): void
    {
        $outcome = $this->scanner()->scan(['%PDF-1.7', "\nan ordinary invoice\n", str_repeat('x', 200_000)]);

        self::assertSame(ScanStatus::Clean, $outcome->status);
    }

    public function testContentOverTheLimitIsNotSent(): void
    {
        $outcome = $this->scanner(maxSize: 100)->scan([str_repeat('x', 101)]);

        self::assertSame(ScanStatus::Failed, $outcome->status);
        self::assertSame(ScanFailure::TooLarge, $outcome->failure);
    }

    /**
     * Only with docker/clamav/clamd.conf (AlertEncrypted), as CI runs it: without, clamd calls the archive clean.
     */
    public function testEncryptedArchiveIsNotCalledClean(): void
    {
        $outcome = $this->scanner()->scan([(string) file_get_contents(__DIR__.'/../../Fixtures/encrypted.zip')]);

        self::assertSame(ScanStatus::Failed, $outcome->status);
        self::assertSame(ScanFailure::Unscannable, $outcome->failure);
    }

    /**
     * Only with docker/clamav/clamd.conf (StreamMaxLength 25M): clamd's own default is 100M.
     */
    public function testStreamPastTheConfiguredLimitIsTooLarge(): void
    {
        $outcome = $this->scanner(maxSize: 64 * 1024 * 1024)->scan((static function (): iterable {
            for ($i = 0; $i < 26; ++$i) {
                yield str_repeat('x', 1024 * 1024);
            }
        })());

        self::assertSame(ScanStatus::Failed, $outcome->status);
        self::assertSame(ScanFailure::TooLarge, $outcome->failure);
    }

    public function testReportsTheEngineAndSignatureVersion(): void
    {
        self::assertMatchesRegularExpression('~^ClamAV [^/]+/\d+/~', $this->scanner()->engine());
    }

    public function testUnreachableScannerIsUnavailable(): void
    {
        $scanner = new ClamdScanner('127.0.0.1', 1, 1024, 1.0, 1.0);

        $this->expectException(ScannerUnavailable::class);

        $scanner->scan(['content']);
    }

    public function testEmptyHostTurnsScanningOff(): void
    {
        self::assertFalse(new ClamdScanner('', 3310, 1024, 1.0, 1.0)->isEnabled());
    }

    private function scanner(int $maxSize = 26_214_400): ClamdScanner
    {
        $host = (string) getenv('CLAMAV_HOST');
        if ('' === $host) {
            if (false !== getenv('CI')) {
                self::fail('CI must run clamd and set CLAMAV_HOST.');
            }
            self::markTestSkipped('Set CLAMAV_HOST to run against a real clamd.');
        }
        $port = (int) (getenv('CLAMAV_PORT') ?: 3310);

        return new ClamdScanner($host, $port, $maxSize, 5.0, 60.0);
    }
}
