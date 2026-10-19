<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Sync\OutboxClaimInput;
use App\ApiResource\Input\Sync\OutboxResultInput;
use App\Security\Scope;
use App\State\SyncOutboxProcessor;
use App\Sync\Outbox\ClaimResult;
use App\Sync\Outbox\OutboxResult;

/**
 * Sending, as seen by the worker that leases an account: take the messages that are due, then report each one.
 * Internal (scope sync:ingest), left out of the API docs.
 */
#[ApiResource(
    shortName: 'SyncOutbox',
    routePrefix: '/internal',
    operations: [
        new Post(
            uriTemplate: '/sync/accounts/{accountId}/outbox/claim',
            status: 200,
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_INGEST."')",
            input: OutboxClaimInput::class,
            output: ClaimResult::class,
            read: false,
            processor: SyncOutboxProcessor::class,
            name: 'internal_sync_outbox_claim',
        ),
        new Post(
            uriTemplate: '/sync/accounts/{accountId}/outbox/{messageId}/result',
            status: 200,
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_INGEST."')",
            input: OutboxResultInput::class,
            output: OutboxResult::class,
            read: false,
            processor: SyncOutboxProcessor::class,
            name: 'internal_sync_outbox_result',
        ),
    ],
)]
final class SyncOutbox
{
}
