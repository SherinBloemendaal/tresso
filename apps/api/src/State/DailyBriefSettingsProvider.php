<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\DailyBriefSettingsResource;
use App\Entity\DailyBriefSettings;
use App\Notification\NotificationDispatcher;
use App\Repository\DailyBriefSettingsRepository;
use App\Repository\NotificationPreferencesRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The signed-in user's brief settings; a user made before briefs existed gets the defaults stored first.
 *
 * @implements ProviderInterface<DailyBriefSettingsResource>
 */
final readonly class DailyBriefSettingsProvider implements ProviderInterface
{
    public function __construct(
        private DailyBriefSettingsRepository $settings,
        private NotificationPreferencesRepository $preferences,
        private NotificationDispatcher $dispatcher,
        private CurrentUser $currentUser,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DailyBriefSettingsResource
    {
        $user = $this->currentUser->get();
        $settings = $this->settings->findOneFor($user);
        if (!$settings instanceof DailyBriefSettings) {
            $this->settings->createMissing($this->clock->now(), $user);
            $settings = $this->settings->findOneFor($user) ?? throw new \LogicException('The brief settings were not created.');
        }
        $this->entityManager->refresh($settings);

        return DailyBriefSettingsResource::fromEntity($settings, $this->preferences->forUser($user), $this->dispatcher->emailAddressFor($user));
    }
}
