<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Mailbox\MessageActionInput;
use App\Security\Scope;
use App\State\MessageActionProcessor;

/**
 * Read, unread, flag, unflag, archive, trash or move messages. The change shows in Tresso at once (clients get it
 * through the delta) and the account's sync worker makes it on the mail server; a change the server refuses is
 * undone, so Tresso keeps showing what the server has.
 */
#[ApiResource(
    shortName: 'MessageAction',
    routePrefix: '/api/v1',
    operations: [
        new Post(
            uriTemplate: '/message-actions',
            status: 200,
            security: "is_granted('".Scope::ROLE_MAIL_WRITE."')",
            input: MessageActionInput::class,
            read: false,
            processor: MessageActionProcessor::class,
        ),
    ],
)]
final readonly class MessageActionResource
{
    public function __construct(
        /** How many of the messages changed; the others already were that way. */
        public int $changed,
    ) {
    }
}
