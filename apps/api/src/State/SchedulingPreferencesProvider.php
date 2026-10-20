<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\SchedulingPreferencesResource;
use App\Repository\SchedulingPreferencesRepository;
use App\Security\CurrentUser;

/**
 * @implements ProviderInterface<SchedulingPreferencesResource>
 */
final readonly class SchedulingPreferencesProvider implements ProviderInterface
{
    public function __construct(
        private SchedulingPreferencesRepository $preferences,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SchedulingPreferencesResource
    {
        return SchedulingPreferencesResource::fromEntity($this->preferences->forUser($this->currentUser->get()));
    }
}
