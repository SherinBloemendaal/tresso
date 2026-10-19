<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\AttachmentContentProvider;

/**
 * The bytes of an attachment, through a signed URL from the message body (no access token: images inside rendered
 * mail cannot send one). Images that are safe to show inline are served as such; everything else downloads.
 */
#[ApiResource(
    shortName: 'AttachmentContent',
    routePrefix: '/content',
    operations: [
        new Get(
            uriTemplate: '/attachments/{id}',
            name: self::ROUTE,
            provider: AttachmentContentProvider::class,
            formats: ['binary' => ['application/octet-stream']],
            openapi: false,
        ),
    ],
)]
final readonly class AttachmentContent
{
    public const string ROUTE = 'attachment_content';

    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
    ) {
    }
}
