<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Sync\UnsubscribeClaimInput;
use App\ApiResource\Input\Sync\UnsubscribeReportInput;
use App\Security\Scope;
use App\State\SyncUnsubscribeProcessor;
use App\Sync\Unsubscribe\UnsubscribeReportResult;
use App\Sync\Unsubscribe\UnsubscribeWork;

/**
 * The user's one-click unsubscribe requests, as seen by the worker that leases the account: take the requests to
 * make, then report each. Internal (scope sync:ingest), left out of the API docs.
 */
#[ApiResource(
    shortName: 'SyncUnsubscribe',
    routePrefix: '/internal',
    operations: [
        new Post(
            uriTemplate: '/sync/accounts/{accountId}/unsubscribes/claim',
            status: 200,
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_INGEST."')",
            input: UnsubscribeClaimInput::class,
            output: UnsubscribeWork::class,
            read: false,
            processor: SyncUnsubscribeProcessor::class,
            name: 'internal_sync_unsubscribe_claim',
        ),
        new Post(
            uriTemplate: '/sync/accounts/{accountId}/unsubscribes/{requestId}/report',
            status: 200,
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_INGEST."')",
            input: UnsubscribeReportInput::class,
            output: UnsubscribeReportResult::class,
            read: false,
            processor: SyncUnsubscribeProcessor::class,
            name: 'internal_sync_unsubscribe_report',
        ),
    ],
)]
final class SyncUnsubscribe
{
}
