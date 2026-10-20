<?php

declare(strict_types=1);

namespace App\Mail\Body;

use App\ApiResource\AttachmentContent;
use App\ApiResource\DraftAttachmentContent;
use App\Entity\Attachment;
use App\Entity\DraftAttachment;
use App\Enum\ScanStatus;
use App\Scan\AttachmentAccess;
use App\Scan\DownloadPolicy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Signed, expiring links to attachment content. They work without an access token, so whoever holds one can fetch
 * the attachment until it expires; that is why they are short-lived and only handed to the message's owner.
 * Only clean attachments get a link with their listing; one that could not be scanned gets one on a confirmed request.
 */
final readonly class AttachmentUrls
{
    private const string LIFETIME = 'PT1H';
    private const string UNSCANNED = 'unscanned';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private UriSigner $signer,
        private DownloadPolicy $downloads,
    ) {
    }

    public function access(ScanStatus $status): AttachmentAccess
    {
        return $this->downloads->access($status);
    }

    /** A link for a clean attachment; null for any other. */
    public function for(Attachment $attachment): ?string
    {
        if (AttachmentAccess::Allowed !== $this->downloads->access($attachment->getScanStatus())) {
            return null;
        }

        return $this->sign(AttachmentContent::ROUTE, $attachment->getId(), false);
    }

    /** The same for a file attached to a draft. */
    public function forDraft(DraftAttachment $attachment): ?string
    {
        if (AttachmentAccess::Allowed !== $this->downloads->access($attachment->getScanStatus())) {
            return null;
        }

        return $this->sign(DraftAttachmentContent::ROUTE, $attachment->getId(), false);
    }

    /**
     * A link for an attachment that could not be scanned, for a user who confirmed they accept that. It carries the
     * confirmation (`unscanned=1`, covered by the signature); null when the policy allows no such download.
     */
    public function confirmedUnscanned(Attachment $attachment): ?string
    {
        if (AttachmentAccess::Confirm !== $this->downloads->access($attachment->getScanStatus())) {
            return null;
        }

        return $this->sign(AttachmentContent::ROUTE, $attachment->getId(), true);
    }

    /** The same for a file attached to a draft. */
    public function confirmedUnscannedForDraft(DraftAttachment $attachment): ?string
    {
        if (AttachmentAccess::Confirm !== $this->downloads->access($attachment->getScanStatus())) {
            return null;
        }

        return $this->sign(DraftAttachmentContent::ROUTE, $attachment->getId(), true);
    }

    /** Whether a verified content request carries the confirmation of {@see confirmedUnscanned()}. */
    public static function isConfirmedUnscanned(Request $request): bool
    {
        return '1' === $request->query->get(self::UNSCANNED);
    }

    private function sign(string $route, Uuid $id, bool $unscanned): string
    {
        return $this->signer->sign(
            $this->urls->generate($route, ['id' => $id->toRfc4122()] + ($unscanned ? [self::UNSCANNED => '1'] : []), UrlGeneratorInterface::ABSOLUTE_URL),
            new \DateInterval(self::LIFETIME),
        );
    }
}
