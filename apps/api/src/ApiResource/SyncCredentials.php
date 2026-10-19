<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Enum\MailProvider;
use App\Security\Scope;
use App\State\SyncCredentialsProvider;

/**
 * Decrypted credentials for one account, handed only to the worker that currently leases it. Internal: scope
 * sync:credentials, excluded from the API docs, never cached.
 */
#[ApiResource(
    shortName: 'SyncCredentials',
    routePrefix: '/internal',
    operations: [
        new Get(
            uriTemplate: '/sync/accounts/{accountId}/credentials',
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_CREDENTIALS."')",
            provider: SyncCredentialsProvider::class,
            name: 'internal_sync_credentials',
        ),
    ],
)]
final readonly class SyncCredentials
{
    /**
     * @param array<string, mixed>       $connection  server settings and username
     * @param array<string, scalar|null> $credentials secrets, e.g. {"type": "password", "password": "..."}
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $accountId,
        public string $emailAddress,
        public MailProvider $provider,
        public array $connection,
        public array $credentials,
    ) {
    }
}
