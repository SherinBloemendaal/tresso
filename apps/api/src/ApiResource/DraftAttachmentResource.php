<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Draft\ForwardAttachmentInput;
use App\ApiResource\Output\DraftAttachmentOutput;
use App\ApiResource\Output\DraftFilesOutput;
use App\Security\Scope;
use App\State\DraftAttachmentProcessor;

/**
 * A file attached to a draft. Uploaded as multipart/form-data (field "file"), or taken from a received message for a
 * forward. It goes out with the message and into the copy in the Drafts folder; `url` downloads it (signed, for an
 * hour) and is null while it is quarantined.
 */
#[ApiResource(
    shortName: 'DraftAttachment',
    routePrefix: '/api/v1',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(
            uriTemplate: '/drafts/{draftId}/attachments',
            uriVariables: ['draftId' => new Link(fromClass: DraftResource::class, identifiers: ['id'])],
            security: "is_granted('".Scope::ROLE_MAIL_WRITE."')",
            inputFormats: ['multipart' => ['multipart/form-data']],
            read: false,
            deserialize: false,
            processor: DraftAttachmentProcessor::class,
            output: DraftAttachmentOutput::class,
            name: 'draft_attachment_upload',
        ),
        new Post(
            uriTemplate: '/drafts/{draftId}/forwarded-attachments',
            uriVariables: ['draftId' => new Link(fromClass: DraftResource::class, identifiers: ['id'])],
            security: "is_granted('".Scope::ROLE_MAIL_WRITE."')",
            input: ForwardAttachmentInput::class,
            read: false,
            processor: DraftAttachmentProcessor::class,
            output: DraftAttachmentOutput::class,
            name: 'draft_attachment_forward',
        ),
        new Delete(
            uriTemplate: '/drafts/{draftId}/attachments/{id}',
            uriVariables: [
                'draftId' => new Link(fromClass: DraftResource::class, identifiers: ['id']),
                'id' => new Link(fromClass: self::class, identifiers: ['id']),
            ],
            status: 200,
            security: "is_granted('".Scope::ROLE_MAIL_WRITE."')",
            output: DraftFilesOutput::class,
            read: false,
            processor: DraftAttachmentProcessor::class,
            name: 'draft_attachment_delete',
        ),
    ],
)]
final readonly class DraftAttachmentResource
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
    ) {
    }
}
