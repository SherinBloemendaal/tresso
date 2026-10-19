<?php

declare(strict_types=1);

namespace App\Mail\Body;

use App\ApiResource\AttachmentContent;
use App\ApiResource\DraftAttachmentContent;
use App\Entity\Attachment;
use App\Entity\DraftAttachment;
use App\Enum\ScanStatus;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Signed, expiring links to attachment content. They work without an access token, so whoever holds one can fetch
 * the attachment until it expires; that is why they are short-lived and only handed to the message's owner.
 */
final readonly class AttachmentUrls
{
    private const string LIFETIME = 'PT1H';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private UriSigner $signer,
    ) {
    }

    public function for(Attachment $attachment): ?string
    {
        if (ScanStatus::Infected === $attachment->getScanStatus()) {
            return null;
        }

        return $this->signer->sign(
            $this->urls->generate(AttachmentContent::ROUTE, ['id' => $attachment->getId()->toRfc4122()], UrlGeneratorInterface::ABSOLUTE_URL),
            new \DateInterval(self::LIFETIME),
        );
    }

    /** The same for a file attached to a draft. */
    public function forDraft(DraftAttachment $attachment): ?string
    {
        if (ScanStatus::Infected === $attachment->getScanStatus()) {
            return null;
        }

        return $this->signer->sign(
            $this->urls->generate(DraftAttachmentContent::ROUTE, ['id' => $attachment->getId()->toRfc4122()], UrlGeneratorInterface::ABSOLUTE_URL),
            new \DateInterval(self::LIFETIME),
        );
    }
}
