<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use App\ApiResource\Output\MessageAttachment;
use App\Security\Scope;
use App\State\MessageBodyProvider;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The readable content of a message, safe to render.
 *
 * `html` is sanitized: no scripts, event handlers, forms, frames or embedded objects; links open outside the app.
 * Content from the internet (images, backgrounds, fonts) is removed unless `remoteContent=allow`, because loading it
 * tells the sender that and where the message was read; `hasRemoteContent` says whether there was any. Inline images
 * point at short-lived signed URLs that need no access token, so the HTML can be rendered as is. Render it in a
 * sandboxed frame without scripts, with a Content-Security-Policy that only allows images from the API (and from the
 * internet when allowed): that is the second layer of protection.
 *
 * Messages without HTML have only `text`. Attachment URLs expire after an hour; fetch the body again for new ones.
 */
#[ApiResource(
    shortName: 'MessageBody',
    routePrefix: '/api/v1',
    operations: [
        new Get(
            uriTemplate: '/messages/{id}/body',
            security: "is_granted('".Scope::ROLE_MAIL_READ."')",
            provider: MessageBodyProvider::class,
            normalizationContext: ['skip_null_values' => false],
            parameters: [
                'remoteContent' => new QueryParameter(
                    schema: ['type' => 'string', 'enum' => ['block', 'allow'], 'default' => 'block'],
                    description: 'Whether images and other content from the internet may load.',
                    constraints: [new Assert\Choice(choices: ['block', 'allow'], message: 'remoteContent must be "block" or "allow".')],
                ),
            ],
        ),
    ],
)]
final readonly class MessageBody
{
    /**
     * @param list<MessageAttachment> $attachments
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public ?string $html,
        public ?string $text,
        public bool $hasRemoteContent,
        public bool $remoteContentLoaded,
        public array $attachments,
    ) {
    }
}
