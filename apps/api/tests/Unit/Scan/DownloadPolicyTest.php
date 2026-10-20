<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scan;

use App\Enum\ScanStatus;
use App\Scan\AttachmentAccess;
use App\Scan\DownloadPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DownloadPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{ScanStatus, bool, AttachmentAccess}>
     */
    public static function access(): iterable
    {
        yield 'clean' => [ScanStatus::Clean, false, AttachmentAccess::Allowed];
        yield 'pending' => [ScanStatus::Pending, false, AttachmentAccess::Scanning];
        yield 'infected' => [ScanStatus::Infected, false, AttachmentAccess::Blocked];
        yield 'not scanned: after a warning' => [ScanStatus::Failed, false, AttachmentAccess::Confirm];
        yield 'not scanned, scans required: never' => [ScanStatus::Failed, true, AttachmentAccess::Blocked];
        yield 'clean, scans required' => [ScanStatus::Clean, true, AttachmentAccess::Allowed];
    }

    #[DataProvider('access')]
    public function testAccess(ScanStatus $status, bool $requireScan, AttachmentAccess $access): void
    {
        self::assertSame($access, new DownloadPolicy($requireScan)->access($status));
    }

    /**
     * @return iterable<string, array{ScanStatus, bool, bool, ?int}>
     */
    public static function downloads(): iterable
    {
        yield 'clean' => [ScanStatus::Clean, false, false, null];
        yield 'pending waits for the scan' => [ScanStatus::Pending, false, true, 409];
        yield 'infected, also when confirmed' => [ScanStatus::Infected, false, true, 403];
        yield 'not scanned, unconfirmed' => [ScanStatus::Failed, false, false, 403];
        yield 'not scanned, confirmed' => [ScanStatus::Failed, false, true, null];
        yield 'not scanned, confirmed, scans required' => [ScanStatus::Failed, true, true, 403];
    }

    #[DataProvider('downloads')]
    public function testDownloads(ScanStatus $status, bool $requireScan, bool $confirmed, ?int $statusCode): void
    {
        self::assertSame($statusCode, new DownloadPolicy($requireScan)->refusal($status, $confirmed)?->getStatusCode());
    }

    public function testPendingAsksToRetryLater(): void
    {
        self::assertSame('15', new DownloadPolicy(false)->refusal(ScanStatus::Pending)?->getHeaders()['Retry-After'] ?? null);
    }

    /**
     * @return iterable<string, array{ScanStatus, bool, bool, bool}>
     */
    public static function sending(): iterable
    {
        yield 'clean' => [ScanStatus::Clean, false, false, true];
        yield 'pending waits' => [ScanStatus::Pending, false, true, false];
        yield 'infected never' => [ScanStatus::Infected, false, true, false];
        yield 'not scanned, unconfirmed' => [ScanStatus::Failed, false, false, false];
        yield 'not scanned, confirmed' => [ScanStatus::Failed, false, true, true];
        yield 'not scanned, confirmed, scans required' => [ScanStatus::Failed, true, true, false];
    }

    #[DataProvider('sending')]
    public function testSending(ScanStatus $status, bool $requireScan, bool $acceptUnscanned, bool $goes): void
    {
        self::assertSame($goes, null === new DownloadPolicy($requireScan)->sendingRefusal($status, 'notes.txt', $acceptUnscanned));
    }
}
