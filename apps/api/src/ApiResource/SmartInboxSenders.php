<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\ApiResource\Output\SenderStatistic;
use App\Security\Scope;
use App\State\SmartInboxSendersProvider;

/**
 * What a smart inbox lists, in numbers, and who sends it: the senders with the most conversations first (at most
 * 100), without the user's own addresses. Acting on all of it, or on one sender, goes through `POST /bulk-actions`
 * with `in: smartInbox`.
 */
#[ApiResource(
    shortName: 'SmartInboxSenders',
    routePrefix: '/api/v1',
    operations: [
        new Get(
            uriTemplate: '/smart-inboxes/{id}/senders',
            security: "is_granted('".Scope::ROLE_MAIL_READ."')",
            provider: SmartInboxSendersProvider::class,
        ),
    ],
)]
final readonly class SmartInboxSenders
{
    /**
     * @param list<SenderStatistic> $senders
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public int $conversations,
        public int $messages,
        public int $unread,
        public array $senders,
    ) {
    }
}
