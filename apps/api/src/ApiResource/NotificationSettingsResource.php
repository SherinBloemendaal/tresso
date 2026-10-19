<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use App\ApiResource\Input\Notification\UpdateNotificationSettings;
use App\Entity\NotificationPreferences;
use App\Security\Scope;
use App\State\NotificationSettingsProcessor;
use App\State\NotificationSettingsProvider;

/**
 * Quiet hours, focus, the channels per kind of notification and where email notifications go.
 */
#[ApiResource(
    shortName: 'NotificationSettings',
    routePrefix: '/api/v1',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/notification-settings', uriVariables: [], security: "is_granted('".Scope::ROLE_SETTINGS_READ."')", provider: NotificationSettingsProvider::class),
        new Patch(uriTemplate: '/notification-settings', uriVariables: [], security: "is_granted('".Scope::ROLE_SETTINGS_WRITE."')", input: UpdateNotificationSettings::class, provider: NotificationSettingsProvider::class, processor: NotificationSettingsProcessor::class),
    ],
)]
final readonly class NotificationSettingsResource
{
    /**
     * @param list<int> $quietDays
     * @param object    $channelsByKind the user's choices, kind to list of channels; kinds missing here use their
     *                                  default (an object, so an empty one is {} in JSON)
     */
    public function __construct(
        public string $timeZone,
        public bool $quietHoursEnabled,
        public array $quietDays,
        public string $quietStart,
        public string $quietEnd,
        public bool $focusDuringBusy,
        public ?string $emailAddress,
        public string $effectiveEmailAddress,
        public object $channelsByKind,
    ) {
    }

    public static function fromEntity(NotificationPreferences $preferences): self
    {
        return new self(
            $preferences->getTimeZone(),
            $preferences->isQuietHoursEnabled(),
            $preferences->getQuietDays(),
            $preferences->getQuietStart(),
            $preferences->getQuietEnd(),
            $preferences->isFocusDuringBusy(),
            $preferences->getEmailAddress(),
            $preferences->getEmailAddress() ?? $preferences->getUser()->getEmail(),
            (object) $preferences->getChannelsByKind(),
        );
    }
}
