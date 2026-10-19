<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Put;
use App\ApiResource\Input\SmartInbox\ThreadClassificationInput;
use App\ApiResource\Output\RememberedSender;
use App\Security\Scope;
use App\State\ThreadClassificationProcessor;

/**
 * The user's correction of where a conversation belongs. The conversation and its newest incoming message move to the
 * smart inbox with the verdict and stay there; the choice is remembered for the sender as a sender rule, so their
 * next mail lands there too, and recent mail from them is classified again.
 */
#[ApiResource(
    shortName: 'ThreadClassification',
    routePrefix: '/api/v1',
    operations: [
        new Put(
            uriTemplate: '/threads/{threadId}/classification',
            status: 200,
            security: "is_granted('".Scope::ROLE_MAIL_WRITE."')",
            input: ThreadClassificationInput::class,
            read: false,
            processor: ThreadClassificationProcessor::class,
            allowCreate: true,
        ),
    ],
)]
final readonly class ThreadClassificationResource
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $threadId,
        public string $smartInboxId,
        public string $verdict,
        public string $reason,
        /** The rule the choice was remembered as; null when the conversation has only the user's own mail. */
        public ?RememberedSender $senderRule,
    ) {
    }
}
