<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\BatchDeliveryResource;
use App\Batch\BatchDelivery;
use App\Batch\BatchTimes;
use App\Security\CurrentUser;

/**
 * @implements ProviderInterface<BatchDeliveryResource>
 */
final readonly class BatchDeliveryProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private BatchDelivery $batches,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BatchDeliveryResource
    {
        $schedule = $this->batches->schedule($this->currentUser->get());

        return BatchDeliveryResource::from($schedule?->batchTimes() ?? BatchTimes::defaults(), null !== $schedule, new \DateTimeImmutable());
    }
}
