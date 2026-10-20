<?php

declare(strict_types=1);

namespace App\Scan;

use App\Enum\ScanStatus;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * What may be done with an attachment (received, or attached to a draft), from its scan:
 *
 * - clean: download, forward and send;
 * - pending: nothing yet; an older link is answered with 409 and Retry-After until the scan is done;
 * - infected: never;
 * - failed (too large, encrypted, past clamd's limits, scanner unavailable or turned off): only after the user
 *   confirmed they accept a file that was not checked, for each download and for each message that sends it. With
 *   ATTACHMENTS_REQUIRE_SCAN on, never: for deployments that accept no unchecked file at all.
 */
final readonly class DownloadPolicy
{
    /** Seconds a client should wait before asking again for an attachment that is still being scanned. */
    private const int RETRY_AFTER = 15;

    public function __construct(
        #[Autowire(env: 'bool:ATTACHMENTS_REQUIRE_SCAN')]
        private bool $requireScan,
    ) {
    }

    public function requiresScan(): bool
    {
        return $this->requireScan;
    }

    public function access(ScanStatus $status): AttachmentAccess
    {
        return match ($status) {
            ScanStatus::Clean => AttachmentAccess::Allowed,
            ScanStatus::Pending => AttachmentAccess::Scanning,
            ScanStatus::Infected => AttachmentAccess::Blocked,
            ScanStatus::Failed => $this->requireScan ? AttachmentAccess::Blocked : AttachmentAccess::Confirm,
        };
    }

    /**
     * Why the content may not be served, or null when it may.
     *
     * @param bool $confirmed whether the link was issued after the user accepted a file that was not scanned
     */
    public function refusal(ScanStatus $status, bool $confirmed = false): ?HttpException
    {
        return match ($this->access($status)) {
            AttachmentAccess::Allowed => null,
            AttachmentAccess::Scanning => new ConflictHttpException('This attachment is still being scanned for malware.', headers: ['Retry-After' => (string) self::RETRY_AFTER]),
            AttachmentAccess::Confirm => $confirmed ? null : new AccessDeniedHttpException('This attachment could not be scanned for malware. Ask for a link that confirms you accept that.'),
            AttachmentAccess::Blocked => new AccessDeniedHttpException(ScanStatus::Infected === $status
                ? 'This attachment is quarantined because it contains malware.'
                : 'This attachment could not be scanned for malware, and this server only serves scanned files.'),
        };
    }

    /**
     * Why the file cannot go out with a message, or null when it can.
     *
     * @param bool $acceptUnscanned whether the user confirmed sending files that could not be scanned
     */
    public function sendingRefusal(ScanStatus $status, string $filename, bool $acceptUnscanned): ?string
    {
        return match ($this->access($status)) {
            AttachmentAccess::Allowed => null,
            AttachmentAccess::Scanning => \sprintf('"%s" is still being scanned for malware. Try again in a moment.', $filename),
            AttachmentAccess::Confirm => $acceptUnscanned ? null : \sprintf('"%s" could not be scanned for malware. Confirm that you want to send it anyway.', $filename),
            AttachmentAccess::Blocked => ScanStatus::Infected === $status
                ? \sprintf('"%s" contains malware. Remove it to send this message.', $filename)
                : \sprintf('"%s" could not be scanned for malware, and this server only sends scanned files. Remove it to send this message.', $filename),
        };
    }
}
