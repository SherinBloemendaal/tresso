<?php

declare(strict_types=1);

namespace App\Controller\Internal;

use App\Security\Scope;
use App\Sync\AccountLeaser;
use App\Sync\LeaseRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(Scope::ROLE_SYNC_LEASE)]
final readonly class SyncLeaseController
{
    public function __construct(private AccountLeaser $leaser)
    {
    }

    /** Heartbeat: renew held leases and claim new accounts up to capacity. */
    #[Route('/internal/sync/leases', name: 'internal_sync_lease', methods: ['POST'])]
    public function lease(#[MapRequestPayload] LeaseRequest $request): JsonResponse
    {
        return new JsonResponse([
            'leaseSeconds' => AccountLeaser::LEASE_SECONDS,
            'accounts' => $this->leaser->lease($request->workerId, $request->capacity),
        ]);
    }

    /** Graceful shutdown: give all accounts back so other workers take over immediately. */
    #[Route('/internal/sync/leases/release', name: 'internal_sync_release', methods: ['POST'])]
    public function release(#[MapRequestPayload] LeaseRequest $request): JsonResponse
    {
        return new JsonResponse(['released' => $this->leaser->release($request->workerId)], Response::HTTP_OK);
    }
}
