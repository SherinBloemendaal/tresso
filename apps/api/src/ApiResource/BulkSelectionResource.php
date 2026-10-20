<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Security\Scope;
use App\State\BulkSelectionProvider;

/**
 * How many conversations and messages a bulk action would take, before starting it. The query takes the scope fields
 * of `POST /bulk-actions`: `in`, `smartInboxId`, `mailAccountId`, `sender`, `olderThanDays` and `largerThan`.
 */
#[ApiResource(
    shortName: 'BulkSelection',
    routePrefix: '/api/v1',
    operations: [
        new Get(
            uriTemplate: '/bulk-selection',
            uriVariables: [],
            security: "is_granted('".Scope::ROLE_MAIL_READ."')",
            provider: BulkSelectionProvider::class,
        ),
    ],
)]
final readonly class BulkSelectionResource
{
    /**
     * @param list<string> $mailAccountIds
     */
    public function __construct(
        public int $conversations,
        public int $messages,
        /** The accounts the conversations are in. */
        public array $mailAccountIds,
    ) {
    }
}
