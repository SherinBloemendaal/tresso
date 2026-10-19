<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\DraftAttachmentContentProvider;

/**
 * The content of a file attached to a draft, behind a signed URL (see AttachmentUrls), so the compose screen can open
 * it like a received attachment.
 */
#[ApiResource(
    shortName: 'DraftAttachmentContent',
    routePrefix: '/content',
    operations: [
        new Get(
            uriTemplate: '/draft-attachments/{id}',
            name: self::ROUTE,
            provider: DraftAttachmentContentProvider::class,
            formats: ['binary' => ['application/octet-stream']],
            openapi: false,
        ),
    ],
)]
final readonly class DraftAttachmentContent
{
    public const string ROUTE = 'draft_attachment_content';

    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
    ) {
    }
}
