<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\ApiResource\Input\Brief\DailyBriefSettingsInput;
use App\Brief\DailyBriefDelivery;
use App\Entity\DailyBriefSettings;
use App\Entity\NotificationPreferences;
use App\Security\Scope;
use App\State\DailyBriefSettingsProcessor;
use App\State\DailyBriefSettingsProvider;

/**
 * Whether and when the signed-in user gets the daily brief of `know` mail, and where it goes besides the app, which
 * always shows it. The time is read in the user's notification time zone; the channels are the notification
 * preferences' channels for kind `daily_brief`, and the email goes to the notification address. PUT replaces the
 * fields it takes.
 */
#[ApiResource(
    shortName: 'DailyBriefSettings',
    routePrefix: '/api/v1',
    operations: [
        new Get(
            uriTemplate: '/daily-brief-settings',
            uriVariables: [],
            security: "is_granted('".Scope::ROLE_SETTINGS_READ."')",
            provider: DailyBriefSettingsProvider::class,
            normalizationContext: ['skip_null_values' => false],
        ),
        new Put(
            uriTemplate: '/daily-brief-settings',
            uriVariables: [],
            status: 200,
            security: "is_granted('".Scope::ROLE_SETTINGS_WRITE."')",
            input: DailyBriefSettingsInput::class,
            read: false,
            processor: DailyBriefSettingsProcessor::class,
            allowCreate: true,
            normalizationContext: ['skip_null_values' => false],
        ),
    ],
)]
final readonly class DailyBriefSettingsResource
{
    /**
     * @param list<string> $channels where the brief goes besides the app: `phone`, `desktop`, `email`
     */
    public function __construct(
        public bool $enabled,
        /** HH:MM in timeZone. */
        public string $deliverAt,
        /** The user's notification zone (read only; devices keep it current). */
        public string $timeZone,
        public array $channels,
        /** Where the email digest goes: the notification address (read only). */
        public string $emailAddress,
        /** When the next brief is made; null while the brief is off. */
        public ?\DateTimeImmutable $nextBriefAt,
        #[ApiProperty(identifier: true)]
        public string $id = 'me',
    ) {
    }

    public static function fromEntity(DailyBriefSettings $settings, NotificationPreferences $preferences, string $emailAddress): self
    {
        return new self(
            $settings->isEnabled(),
            $settings->getDeliverAt(),
            $preferences->getTimeZone(),
            DailyBriefDelivery::channelsFor($preferences)->values(),
            $emailAddress,
            $settings->isEnabled() ? $settings->getNextRunAt() : null,
        );
    }
}
