<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Notification\LocationInput;
use App\ApiResource\UserLocationResource;
use App\Entity\UserLocation;
use App\Notification\PlanUserNotifications;
use App\Realtime\AfterCommitDispatcher;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A new position replans leave-now reminders; forgetting it drops the estimated ones.
 *
 * @implements ProcessorInterface<LocationInput|null, UserLocationResource|null>
 */
final readonly class UserLocationProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CurrentUser $currentUser,
        private AfterCommitDispatcher $afterCommit,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?UserLocationResource
    {
        $user = $this->currentUser->get();

        return $this->entityManager->wrapInTransaction(function () use ($user, $data, $operation): ?UserLocationResource {
            $location = $this->entityManager->find(UserLocation::class, $user);
            $this->afterCommit->dispatch(new PlanUserNotifications($user->getId()->toRfc4122()));
            if ($operation instanceof DeleteOperationInterface) {
                if (null !== $location) {
                    $this->entityManager->remove($location);
                    $this->entityManager->flush();
                }

                return null;
            }
            \assert($data instanceof LocationInput);
            $now = new \DateTimeImmutable();
            if (null === $location) {
                $location = new UserLocation($user, $data->latitude, $data->longitude, $now);
                $this->entityManager->persist($location);
            } else {
                $location->move($data->latitude, $data->longitude, $now);
            }
            $this->entityManager->flush();

            return new UserLocationResource($location->getLatitude(), $location->getLongitude(), $location->getRecordedAt());
        });
    }
}
