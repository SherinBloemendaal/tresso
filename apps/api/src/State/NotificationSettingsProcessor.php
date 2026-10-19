<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Notification\UpdateNotificationSettings;
use App\ApiResource\NotificationSettingsResource;
use App\Entity\NotificationPreferences;
use App\Notification\NotificationChannelChoice;
use App\Notification\PlanUserNotifications;
use App\Realtime\AfterCommitDispatcher;
use App\Repository\NotificationPreferencesRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @implements ProcessorInterface<UpdateNotificationSettings, NotificationSettingsResource>
 */
final readonly class NotificationSettingsProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private NotificationPreferencesRepository $preferences,
        private CurrentUser $currentUser,
        private AfterCommitDispatcher $afterCommit,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): NotificationSettingsResource
    {
        $user = $this->currentUser->get();

        return NotificationSettingsResource::fromEntity($this->entityManager->wrapInTransaction(function () use ($user, $data): NotificationPreferences {
            $preferences = $this->preferences->forUser($user);
            $zoneChanged = null !== $data->timeZone && $data->timeZone !== $preferences->getTimeZone();
            if (null !== $data->timeZone) {
                $preferences->setTimeZone($data->timeZone);
            }
            $preferences->setQuietHours(
                $data->quietHoursEnabled ?? $preferences->isQuietHoursEnabled(),
                null === $data->quietDays ? $preferences->getQuietDays() : array_map(intval(...), $data->quietDays),
                $data->quietStart ?? $preferences->getQuietStart(),
                $data->quietEnd ?? $preferences->getQuietEnd(),
            );
            if (null !== $data->focusDuringBusy) {
                $preferences->setFocusDuringBusy($data->focusDuringBusy);
            }
            if (null !== $data->emailAddress) {
                $preferences->setEmailAddress('' === trim($data->emailAddress) ? null : trim($data->emailAddress));
            }
            $channelsBefore = $preferences->getChannelsByKind();
            foreach ($data->channelsByKind ?? [] as $kind => $channels) {
                $preferences->setChannelsFor((string) $kind, \is_array($channels) ? NotificationChannelChoice::fromValues(array_values($channels)) : null);
            }
            $this->entityManager->flush();
            if ($zoneChanged || $channelsBefore !== $preferences->getChannelsByKind()) {
                // All-day reminders fire at a time of day in the user's zone; planned notifications carry channels.
                $this->afterCommit->dispatch(new PlanUserNotifications($user->getId()->toRfc4122()));
            }

            return $preferences;
        }));
    }
}
