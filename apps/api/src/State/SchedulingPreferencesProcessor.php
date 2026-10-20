<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Calendar\UpdateSchedulingPreferences;
use App\ApiResource\SchedulingPreferencesResource;
use App\Repository\SchedulingPreferencesRepository;
use App\Realtime\AfterCommitDispatcher;
use App\Security\CurrentUser;
use App\Todo\ReplanTodos;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Changes the user's scheduling preferences, creating them on the first change (also when two first changes come at
 * once). Switching todo planning on plans the open todos that can be, in the background.
 *
 * @implements ProcessorInterface<UpdateSchedulingPreferences, SchedulingPreferencesResource>
 */
final readonly class SchedulingPreferencesProcessor implements ProcessorInterface
{
    public function __construct(
        private SchedulingPreferencesRepository $preferences,
        private CurrentUser $currentUser,
        private EntityManagerInterface $entityManager,
        private AfterCommitDispatcher $afterCommit,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SchedulingPreferencesResource
    {
        return $this->entityManager->wrapInTransaction(function () use ($data): SchedulingPreferencesResource {
            $user = $this->currentUser->get();
            $preferences = $this->preferences->lockForUser($user);
            if (null !== $data->bufferMinutes) {
                $preferences->setBufferMinutes($data->bufferMinutes);
            }
            if (null !== $data->travelMinutes) {
                $preferences->setTravelMinutes($data->travelMinutes);
            }
            if (null !== $data->minimumNoticeMinutes) {
                $preferences->setMinimumNoticeMinutes($data->minimumNoticeMinutes);
            }
            if (null !== $data->planTodos && $data->planTodos !== $preferences->plansTodos()) {
                $preferences->setPlanTodos($data->planTodos);
                if ($data->planTodos) {
                    $this->afterCommit->dispatch(new ReplanTodos($user->getId()->toRfc4122(), planOpen: true));
                }
            }
            $this->entityManager->flush();

            return SchedulingPreferencesResource::fromEntity($preferences);
        });
    }
}
