<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Sync\AccountStatusInput;
use App\ApiResource\Input\Sync\ChangeBatchInput;
use App\ApiResource\Input\Sync\FolderListInput;
use App\ApiResource\Input\Sync\MessageBatchInput;
use App\Security\Scope;
use App\State\SyncIngestProcessor;
use App\Sync\Ingest\AccountStatusResult;
use App\Sync\Ingest\ChangeBatchResult;
use App\Sync\Ingest\FolderSyncResult;
use App\Sync\Ingest\MessageBatchResult;

/**
 * The ingest protocol: what a sync worker reports about an account it leases. Internal (scope sync:ingest), left out
 * of the API docs. Every call is safe to repeat.
 */
#[ApiResource(
    shortName: 'SyncAccount',
    routePrefix: '/internal',
    operations: [
        // The complete folder list. Answers with each folder's stored cursor, or a reset after UIDVALIDITY changed.
        new Post(
            uriTemplate: '/sync/accounts/{accountId}/folders',
            status: 200,
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_INGEST."')",
            input: FolderListInput::class,
            output: FolderSyncResult::class,
            // A missing cursor is an explicit null: it tells the worker to start the folder from scratch.
            normalizationContext: ['skip_null_values' => false],
            read: false,
            processor: SyncIngestProcessor::class,
            name: 'internal_sync_folders',
        ),
        // New messages in one folder, plus the folder's cursor after the batch.
        new Post(
            uriTemplate: '/sync/accounts/{accountId}/messages',
            status: 200,
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_INGEST."')",
            input: MessageBatchInput::class,
            output: MessageBatchResult::class,
            read: false,
            processor: SyncIngestProcessor::class,
            name: 'internal_sync_messages',
        ),
        // Flag changes and removed messages in one folder.
        new Post(
            uriTemplate: '/sync/accounts/{accountId}/changes',
            status: 200,
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_INGEST."')",
            input: ChangeBatchInput::class,
            output: ChangeBatchResult::class,
            read: false,
            processor: SyncIngestProcessor::class,
            name: 'internal_sync_changes',
        ),
        // The account works (again), its login was refused, or syncing failed.
        new Post(
            uriTemplate: '/sync/accounts/{accountId}/status',
            status: 200,
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_INGEST."')",
            input: AccountStatusInput::class,
            output: AccountStatusResult::class,
            read: false,
            processor: SyncIngestProcessor::class,
            name: 'internal_sync_status',
        ),
    ],
)]
final class SyncAccount
{
}
