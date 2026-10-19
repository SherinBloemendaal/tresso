<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Security\Scope;
use App\State\SyncRealtimeTokenProvider;

/**
 * Lets a sync worker listen for accounts with mail to send (updates of type "outbox" with `{"mailAccountId": ...}`).
 * `hubUrl` is the hub's address inside the deployment. Internal (scope sync:lease).
 */
#[ApiResource(
    shortName: 'SyncRealtimeToken',
    routePrefix: '/internal',
    operations: [
        new Get(
            uriTemplate: '/sync/realtime-token',
            uriVariables: [],
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_LEASE."')",
            provider: SyncRealtimeTokenProvider::class,
            name: 'internal_sync_realtime_token',
        ),
    ],
)]
final readonly class SyncRealtimeToken
{
    public function __construct(
        public string $hubUrl,
        #[ApiProperty(identifier: true)]
        public string $topic,
        public string $token,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
