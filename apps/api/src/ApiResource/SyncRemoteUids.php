<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Security\Scope;
use App\State\SyncRemoteUidsProvider;

/**
 * The remote uids the API stores for one folder of a leased account, ascending by byte order, a page at a time
 * (`?workerId=&folder=<remote name>&after=<next of the previous page>&limit=1..5000`). Placeholder copies of moves
 * that have not landed yet (`pending:<change id>`) are left out.
 *
 * Gmail and Microsoft Graph report deletions through their history and delta feeds. When those expire, the worker
 * lists the folder at the provider, compares it with this list and reports what is gone. Internal: scope sync:ingest.
 */
#[ApiResource(
    shortName: 'SyncRemoteUids',
    routePrefix: '/internal',
    operations: [
        new Get(
            uriTemplate: '/sync/accounts/{accountId}/folders/remote-uids',
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_INGEST."')",
            provider: SyncRemoteUidsProvider::class,
            name: 'internal_sync_remote_uids',
            normalizationContext: ['skip_null_values' => false],
        ),
    ],
)]
final readonly class SyncRemoteUids
{
    /**
     * @param list<string> $remoteUids
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $accountId,
        public string $folder,
        public array $remoteUids,
        /** Pass as `after` for the next page; null on the last page. */
        public ?string $next,
    ) {
    }
}
