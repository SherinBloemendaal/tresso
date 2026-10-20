<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Calendar\UpdateSchedulingPreferences;
use App\ApiResource\SchedulingPreferencesResource;
use App\Entity\SchedulingPreferences;
use App\Repository\SchedulingPreferencesRepository;
use App\Security\CurrentUser;
use App\Todo\TodoReplanning;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Changes the user's scheduling preferences, creating them on the first change (also when two first changes come at
 * once). Planned todos are checked again in the background after any change, as what keeps time free changed;
 * switching todo planning on plans the open todos that can be.
 *
 * @implements ProcessorInterface<UpdateSchedulingPreferences, SchedulingPreferencesResource>
 */
final readonly class SchedulingPreferencesProcessor implements ProcessorInterface
{
    public function __construct(
        private SchedulingPreferencesRepository $preferences,
        private CurrentUser $currentUser,
        private EntityManagerInterface $entityManager,
        private TodoReplanning $todoReplanning,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SchedulingPreferencesResource
    {
        return $this->entityManager->wrapInTransaction(function () use ($data): SchedulingPreferencesResource {
            $user = $this->currentUser->get();
            $preferences = $this->preferences->lockForUser($user);
            $before = self::planning($preferences);
            if (null !== $data->bufferMinutes) {
                $preferences->setBufferMinutes($data->bufferMinutes);
            }
            if (null !== $data->travelMinutes) {
                $preferences->setTravelMinutes($data->travelMinutes);
            }
            if (null !== $data->minimumNoticeMinutes) {
                $preferences->setMinimumNoticeMinutes($data->minimumNoticeMinutes);
            }
            if (null !== $data->planTodos) {
                $preferences->setPlanTodos($data->planTodos);
            }
            if ($before !== self::planning($preferences)) {
                $this->todoReplanning->afterCommit($user);
            }
            $this->entityManager->flush();

            return SchedulingPreferencesResource::fromEntity($preferences);
        });
    }

    /**
     * What planned todos depend on, to see whether a change needs them checked again.
     *
     * @return list<int|bool>
     */
    private static function planning(SchedulingPreferences $preferences): array
    {
        return [$preferences->getBufferMinutes(), $preferences->getTravelMinutes(), $preferences->getMinimumNoticeMinutes(), $preferences->plansTodos()];
    }
}
