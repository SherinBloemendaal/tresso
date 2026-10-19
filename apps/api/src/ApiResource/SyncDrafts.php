<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Sync\DraftClaimInput;
use App\ApiResource\Input\Sync\DraftFiledInput;
use App\ApiResource\Input\Sync\DraftRemovedInput;
use App\Security\Scope;
use App\State\SyncDraftsProcessor;
use App\Sync\Drafts\DraftWork;
use App\Sync\Drafts\DraftWorkResult;

/**
 * The Drafts folder, as seen by the worker that leases an account: take the copies to file and remove, then report
 * each. Internal (scope sync:ingest), left out of the API docs.
 */
#[ApiResource(
    shortName: 'SyncDrafts',
    routePrefix: '/internal',
    operations: [
        new Post(
            uriTemplate: '/sync/accounts/{accountId}/drafts/claim',
            status: 200,
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_INGEST."')",
            input: DraftClaimInput::class,
            output: DraftWork::class,
            normalizationContext: ['skip_null_values' => false],
            read: false,
            processor: SyncDraftsProcessor::class,
            name: 'internal_sync_drafts_claim',
        ),
        new Post(
            uriTemplate: '/sync/accounts/{accountId}/drafts/{draftId}/filed',
            status: 200,
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_INGEST."')",
            input: DraftFiledInput::class,
            output: DraftWorkResult::class,
            read: false,
            processor: SyncDraftsProcessor::class,
            name: 'internal_sync_drafts_filed',
        ),
        new Post(
            uriTemplate: '/sync/accounts/{accountId}/draft-removals/{removalId}/done',
            status: 200,
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_INGEST."')",
            input: DraftRemovedInput::class,
            output: DraftWorkResult::class,
            read: false,
            processor: SyncDraftsProcessor::class,
            name: 'internal_sync_draft_removals_done',
        ),
    ],
)]
final class SyncDrafts
{
}
