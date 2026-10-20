<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\DailyBriefSettingsResource;
use App\ApiResource\Input\Brief\DailyBriefSettingsInput;
use App\Brief\DailyBriefDelivery;
use App\Notification\NotificationChannelChoice;
use App\Notification\NotificationDispatcher;
use App\Repository\DailyBriefSettingsRepository;
use App\Repository\NotificationPreferencesRepository;
use App\Security\CurrentUser;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Replaces the signed-in user's brief settings, stores its channels in the notification preferences, and plans the
 * next brief at the new time.
 *
 * @implements ProcessorInterface<DailyBriefSettingsInput, DailyBriefSettingsResource>
 */
final readonly class DailyBriefSettingsProcessor implements ProcessorInterface
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

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DailyBriefSettingsResource
    {
        $enabled = $data->enabled ?? throw new \LogicException('The validated brief settings have no enabled.');
        $deliverAt = $data->deliverAt ?? throw new \LogicException('The validated brief settings have no time.');
        $channels = NotificationChannelChoice::fromValues($data->channels ?? throw new \LogicException('The validated brief settings have no channels.'));
        $user = $this->currentUser->get();

        return $this->entityManager->wrapInTransaction(function () use ($user, $enabled, $deliverAt, $channels): DailyBriefSettingsResource {
            $now = $this->clock->now();
            $this->settings->createMissing($now, $user);
            $settings = $this->settings->findOneFor($user) ?? throw new \LogicException('The brief settings were not created.');
            // Waits for a brief being made for the user right now, which would otherwise plan the old time again.
            $this->entityManager->refresh($settings, LockMode::PESSIMISTIC_WRITE);
            $preferences = $this->preferences->forUser($user);
            $settings->change($enabled, $deliverAt, $preferences->getTimeZone(), $now);
            $preferences->setChannelsFor(DailyBriefDelivery::KIND, $channels);
            $this->entityManager->flush();

            return DailyBriefSettingsResource::fromEntity($settings, $preferences, $this->dispatcher->emailAddressFor($user));
        });
    }
}
