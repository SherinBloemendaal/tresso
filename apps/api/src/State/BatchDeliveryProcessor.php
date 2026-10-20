<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\BatchDeliveryResource;
use App\ApiResource\Input\Batch\BatchScheduleInput;
use App\Batch\BatchDelivery;
use App\Classification\UserLocks;
use App\Entity\BatchSchedule;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Saves the user's batch times and moves mail that waits to the next batch of the new schedule.
 *
 * @implements ProcessorInterface<BatchScheduleInput, BatchDeliveryResource>
 */
final readonly class BatchDeliveryProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private BatchDelivery $batches,
        private EntityManagerInterface $entityManager,
        private UserLocks $locks,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BatchDeliveryResource
    {
        $user = $this->currentUser->get();

        return $this->entityManager->wrapInTransaction(function () use ($data, $user): BatchDeliveryResource {
            // Classification holds mail under this lock, so nothing is held for the old schedule after this moved it.
            $this->locks->classification($user->getId());
            $schedule = $this->batches->schedule($user);
            if (null === $schedule) {
                $schedule = new BatchSchedule($user, $data->times, $data->timeZone);
                $this->entityManager->persist($schedule);
            } else {
                $schedule->change($data->times, $data->timeZone);
            }
            $this->entityManager->flush();
            $now = new \DateTimeImmutable();
            $this->batches->reschedule($user, $schedule->batchTimes(), $now);

            return BatchDeliveryResource::from($schedule->batchTimes(), true, $now);
        });
    }
}
