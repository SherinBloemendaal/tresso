<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scan;

use App\Enum\ScanFailure;
use App\Enum\ScanStatus;
use App\Scan\Clamd\ClamdReply;
use App\Scan\ScanError;
use App\Scan\ScannerUnavailable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClamdReplyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ScanStatus, ?string, ?ScanFailure}>
     */
    public static function replies(): iterable
    {
        yield 'clean' => ["stream: OK\0", ScanStatus::Clean, null, null];
        yield 'infected' => ["stream: Win.Test.EICAR_HDB-1 FOUND\0", ScanStatus::Infected, 'Win.Test.EICAR_HDB-1', null];
        yield 'newline instead of null terminator' => ["stream: Heuristics.Phishing.Email.SpoofedDomain FOUND\n", ScanStatus::Infected, 'Heuristics.Phishing.Email.SpoofedDomain', null];
        yield 'over clamd size limit' => ["INSTREAM size limit exceeded. ERROR\0", ScanStatus::Failed, null, ScanFailure::TooLarge];
        yield 'encrypted archive' => ["stream: Heuristics.Encrypted.Zip FOUND\0", ScanStatus::Failed, null, ScanFailure::Unscannable];
        yield 'past clamd scan limits' => ["stream: Heuristics.Limits.Exceeded.MaxScanSize FOUND\0", ScanStatus::Failed, null, ScanFailure::Unscannable];
    }

    #[DataProvider('replies')]
    public function testParsesReply(string $reply, ScanStatus $status, ?string $signature, ?ScanFailure $failure): void
    {
        $outcome = ClamdReply::parse($reply);

        self::assertSame($status, $outcome->status);
        self::assertSame($signature, $outcome->signature);
        self::assertSame($failure, $outcome->failure);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonsense(): iterable
    {
        yield 'empty' => [''];
        yield 'other command' => ["PONG\0"];
        yield 'found without signature' => ["stream:  FOUND\0"];
    }

    #[DataProvider('nonsense')]
    public function testRejectsAnythingElse(string $reply): void
    {
        $this->expectException(ScannerUnavailable::class);

        ClamdReply::parse($reply);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function errors(): iterable
    {
        yield 'out of memory' => ["stream: Can't allocate memory ERROR\0"];
        yield 'read timeout' => ["COMMAND READ TIMED OUT ERROR\0"];
    }

    #[DataProvider('errors')]
    public function testErrorsOtherThanTheSizeLimitAreRetried(string $reply): void
    {
        $this->expectException(ScanError::class);

        ClamdReply::parse($reply);
    }

    public function testVersionNamesEngineAndSignatures(): void
    {
        self::assertSame('ClamAV 1.5.4/28158/Mon Oct 19 06:26:01 2026', ClamdReply::version("ClamAV 1.5.4/28158/Mon Oct 19 06:26:01 2026\0"));
    }

    public function testVersionWithoutSignatureDatabaseIsUnavailable(): void
    {
        $this->expectException(ScannerUnavailable::class);

        ClamdReply::version("ClamAV 1.5.4\0");
    }
}
