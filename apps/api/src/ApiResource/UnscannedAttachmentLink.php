<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\ConfirmUnscannedInput;
use App\Security\Scope;
use App\State\UnscannedAttachmentLinkProcessor;

/**
 * A download link for an attachment that could not be scanned for malware (`access: confirm`), given only to a user
 * who confirmed they accept that. The link carries that confirmation and, like other attachment links, is signed and
 * valid for an hour. Clean attachments get their usual link; pending, infected or (on a server that requires scans)
 * unscanned ones are refused.
 */
#[ApiResource(
    shortName: 'UnscannedAttachmentLink',
    routePrefix: '/api/v1',
    operations: [
        new Post(
            uriTemplate: '/attachments/{id}/unscanned-link',
            status: 200,
            security: "is_granted('".Scope::ROLE_MAIL_READ."')",
            input: ConfirmUnscannedInput::class,
            read: false,
            processor: UnscannedAttachmentLinkProcessor::class,
            name: self::RECEIVED,
        ),
        new Post(
            uriTemplate: '/draft-attachments/{id}/unscanned-link',
            status: 200,
            security: "is_granted('".Scope::ROLE_MAIL_READ."')",
            input: ConfirmUnscannedInput::class,
            read: false,
            processor: UnscannedAttachmentLinkProcessor::class,
            name: self::DRAFT,
        ),
    ],
)]
final readonly class UnscannedAttachmentLink
{
    public const string RECEIVED = 'attachment_unscanned_link';
    public const string DRAFT = 'draft_attachment_unscanned_link';

    public function __construct(public string $url)
    {
    }
}
