<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\ApiResource\Input\Draft\DraftInput;
use App\ApiResource\Output\DraftAttachmentOutput;
use App\Entity\Draft;
use App\Entity\DraftAttachment;
use App\Enum\DraftKind;
use App\Mail\Body\AttachmentUrls;
use App\Security\Scope;
use App\State\DraftProcessor;
use App\State\DraftProvider;

/**
 * A message being written, synced to every device (the delta carries `drafts`) and filed in the account's Drafts
 * folder. `html` is what the user wrote; the signature and the quoted original are kept apart and added when the
 * message is sent. Every save raises `version`.
 */
#[ApiResource(
    shortName: 'Draft',
    routePrefix: '/api/v1',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(
            uriTemplate: '/drafts',
            security: "is_granted('".Scope::ROLE_MAIL_WRITE."')",
            input: DraftInput::class,
            read: false,
            processor: DraftProcessor::class,
        ),
        new Get(
            uriTemplate: '/drafts/{id}',
            security: "is_granted('".Scope::ROLE_MAIL_READ."')",
            provider: DraftProvider::class,
        ),
        new Put(
            uriTemplate: '/drafts/{id}',
            security: "is_granted('".Scope::ROLE_MAIL_WRITE."')",
            input: DraftInput::class,
            provider: DraftProvider::class,
            processor: DraftProcessor::class,
            allowCreate: false,
        ),
        new Delete(
            uriTemplate: '/drafts/{id}',
            security: "is_granted('".Scope::ROLE_MAIL_WRITE."')",
            provider: DraftProvider::class,
            processor: DraftProcessor::class,
        ),
    ],
)]
final readonly class DraftResource
{
    /**
     * @param list<array{address: string, name: ?string}> $to
     * @param list<array{address: string, name: ?string}> $cc
     * @param list<array{address: string, name: ?string}> $bcc
     * @param list<DraftAttachmentOutput>                  $attachments
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public ?string $mailAccountId,
        public DraftKind $kind,
        public array $to,
        public array $cc,
        public array $bcc,
        public string $subject,
        public string $html,
        public ?string $signatureId,
        public string $quoteHtml,
        public ?string $inReplyToMessageId,
        public int $version,
        public \DateTimeImmutable $updatedAt,
        public array $attachments,
    ) {
    }

    public static function fromEntity(Draft $draft, AttachmentUrls $urls): self
    {
        $recipients = $draft->getRecipients();

        return new self(
            $draft->getId()->toRfc4122(),
            $draft->getMailAccount()?->getId()->toRfc4122(),
            $draft->getKind(),
            $recipients['to'],
            $recipients['cc'],
            $recipients['bcc'],
            $draft->getSubject(),
            $draft->getHtml(),
            $draft->getSignature()?->getId()->toRfc4122(),
            $draft->getQuoteHtml(),
            $draft->getInReplyTo()?->getId()->toRfc4122(),
            $draft->getVersion(),
            $draft->getUpdatedAt(),
            array_map(static fn (DraftAttachment $attachment): DraftAttachmentOutput => DraftAttachmentOutput::fromEntity($attachment, $urls->forDraft($attachment)), $draft->getAttachments()),
        );
    }
}
