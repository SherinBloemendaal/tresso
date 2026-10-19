<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Sync\MailboxClaimInput;
use App\ApiResource\Input\Sync\MailboxReportInput;
use App\Security\Scope;
use App\State\SyncMailboxProcessor;
use App\Sync\Mailbox\MailboxReportResult;
use App\Sync\Mailbox\MailboxWork;

/**
 * The user's changes to messages (read, flagged, moved), as seen by the worker that leases the account: take the
 * changes to make on the server, then report each. Internal (scope sync:ingest), left out of the API docs.
 */
#[ApiResource(
    shortName: 'SyncMailbox',
    routePrefix: '/internal',
    operations: [
        new Post(
            uriTemplate: '/sync/accounts/{accountId}/mailbox-changes/claim',
            status: 200,
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_INGEST."')",
            input: MailboxClaimInput::class,
            output: MailboxWork::class,
            normalizationContext: ['skip_null_values' => false],
            read: false,
            processor: SyncMailboxProcessor::class,
            name: 'internal_sync_mailbox_claim',
        ),
        new Post(
            uriTemplate: '/sync/accounts/{accountId}/mailbox-changes/{changeId}/report',
            status: 200,
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_INGEST."')",
            input: MailboxReportInput::class,
            output: MailboxReportResult::class,
            read: false,
            processor: SyncMailboxProcessor::class,
            name: 'internal_sync_mailbox_report',
        ),
    ],
)]
final class SyncMailbox
{
}
