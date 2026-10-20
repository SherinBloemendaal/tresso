<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Put;
use App\ApiResource\Input\SmartInbox\ThreadClassificationInput;
use App\ApiResource\Output\RememberedSender;
use App\Security\Scope;
use App\State\ThreadClassificationProcessor;
use App\State\ThreadClassificationReleaseProcessor;

/**
 * The user's correction of where a conversation belongs. The conversation and its newest incoming message move to the
 * smart inbox with the verdict and stay there; the choice is remembered for the sender as a sender rule, so their
 * next mail lands there too, and recent mail from them is classified again. With `remember: false` only the
 * conversation moves. Deleting the correction lets the rules place the conversation again.
 */
#[ApiResource(
    shortName: 'ThreadClassification',
    routePrefix: '/api/v1',
    operations: [
        new Put(
            uriTemplate: '/threads/{threadId}/classification',
            status: 200,
            // It changes mail and remembers a rule (and may edit an exclude list): both scopes are needed.
            security: "is_granted('".Scope::ROLE_MAIL_WRITE."') and is_granted('".Scope::ROLE_SETTINGS_WRITE."')",
            input: ThreadClassificationInput::class,
            read: false,
            processor: ThreadClassificationProcessor::class,
            allowCreate: true,
            normalizationContext: ['skip_null_values' => false],
        ),
        new Delete(
            uriTemplate: '/threads/{threadId}/classification',
            // Only the conversation changes; sender rules and smart inboxes stay as they are.
            security: "is_granted('".Scope::ROLE_MAIL_WRITE."')",
            read: false,
            processor: ThreadClassificationReleaseProcessor::class,
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
        /** This placement; taking it back (DELETE) names it, so a later one is never undone with it. */
        public string $placementId,
    ) {
    }
}
