<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Security\Scope;
use App\State\SyncLeaseProcessor;
use App\State\SyncReleaseProcessor;
use App\Sync\LeaseResult;
use App\Sync\ReleaseResult;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A sync worker's lease request. Internal: only the sync-worker client (scope sync:lease) may call it, and it is left
 * out of the public API documentation.
 */
#[ApiResource(
    shortName: 'SyncLease',
    routePrefix: '/internal',
    operations: [
        // Heartbeat: renew held leases and claim new accounts up to capacity.
        new Post(
            uriTemplate: '/sync/leases',
            status: 200,
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_LEASE."')",
            output: LeaseResult::class,
            read: false,
            processor: SyncLeaseProcessor::class,
            name: 'internal_sync_lease',
        ),
        // Graceful shutdown: give all accounts back so other workers take over immediately.
        new Post(
            uriTemplate: '/sync/leases/release',
            status: 200,
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_LEASE."')",
            output: ReleaseResult::class,
            read: false,
            processor: SyncReleaseProcessor::class,
            name: 'internal_sync_release',
        ),
    ],
)]
final class SyncLease
{
    public function __construct(
        /** Stable id of the calling worker process; leases are owned per worker. */
        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        #[Assert\Regex('/^[A-Za-z0-9._:-]+$/')]
        public string $workerId = '',
        /** Maximum number of accounts this worker wants to hold, including the ones it already holds. */
        #[Assert\Range(min: 1, max: 1000)]
        public int $capacity = 100,
    ) {
    }
}
