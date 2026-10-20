<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Calendar\UpdateSchedulingPreferences;
use App\ApiResource\SchedulingPreferencesResource;
use App\Repository\SchedulingPreferencesRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Changes the user's scheduling preferences, creating them on the first change (also when two first changes come at
 * once).
 *
 * @implements ProcessorInterface<UpdateSchedulingPreferences, SchedulingPreferencesResource>
 */
final readonly class SchedulingPreferencesProcessor implements ProcessorInterface
{
    public function __construct(
        private SchedulingPreferencesRepository $preferences,
        private CurrentUser $currentUser,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SchedulingPreferencesResource
    {
        return $this->entityManager->wrapInTransaction(function () use ($data): SchedulingPreferencesResource {
            $preferences = $this->preferences->lockForUser($this->currentUser->get());
            if (null !== $data->bufferMinutes) {
                $preferences->setBufferMinutes($data->bufferMinutes);
            }
            if (null !== $data->travelMinutes) {
                $preferences->setTravelMinutes($data->travelMinutes);
            }
            if (null !== $data->minimumNoticeMinutes) {
                $preferences->setMinimumNoticeMinutes($data->minimumNoticeMinutes);
            }
            $this->entityManager->flush();

            return SchedulingPreferencesResource::fromEntity($preferences);
        });
    }
}
