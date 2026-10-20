<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Security\Scope;
use App\State\SyncStorageKeysProvider;

/**
 * The keys a worker needs to store and read one account's content, handed only to the worker that currently leases
 * it. Internal: scope sync:credentials, excluded from the API docs, never cached.
 *
 * `blobKeys` holds the owner's blob key (base64) of every key version still in use; new content is encrypted with
 * `currentVersion`. `indexKey` names content: fingerprint = hex(HMAC-SHA256(indexKey, SHA-256 of the content)),
 * stored under `<kind>/<ownerId>/<fp[0:2]>/<fp>`. Never a master key, user key or field key. Workers keep these in
 * memory for at most 5 minutes.
 */
#[ApiResource(
    shortName: 'SyncStorageKeys',
    routePrefix: '/internal',
    operations: [
        new Get(
            uriTemplate: '/sync/accounts/{accountId}/storage-keys',
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_CREDENTIALS."')",
            provider: SyncStorageKeysProvider::class,
            name: 'internal_sync_storage_keys',
        ),
    ],
)]
final readonly class SyncStorageKeys
{
    /**
     * @param list<array{version: int, key: string}> $blobKeys
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $accountId,
        public string $ownerId,
        public string $indexKey,
        public int $currentVersion,
        public array $blobKeys,
    ) {
    }
}
