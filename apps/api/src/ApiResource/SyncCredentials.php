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
 *
 * IMAP accounts get {"type": "password", "password"}. Gmail and Microsoft 365 accounts get
 * {"type": "oauth2", "accessToken", "expiresAt"}: a provider access token the API refreshed when needed; the refresh
 * token never leaves the API. `?rejected=1` says the provider refused the last access token, so the API gets a new
 * one. 409 means the user has to sign in with the provider again (the account was marked as a failed sign-in);
 * 503 means the provider could not be reached and the worker should retry later.
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
            normalizationContext: ['skip_null_values' => false],
        ),
    ],
)]
final readonly class SyncCredentials
{
    /**
     * @param array<string, mixed>|null  $connection  server settings and username; null for Gmail and Microsoft 365
     * @param array<string, scalar|null> $credentials secrets, e.g. {"type": "password", "password": "..."}
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $accountId,
        public string $emailAddress,
        public MailProvider $provider,
        public ?array $connection,
        public array $credentials,
    ) {
    }
}
