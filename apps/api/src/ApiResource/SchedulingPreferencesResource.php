<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use App\ApiResource\Input\Calendar\UpdateSchedulingPreferences;
use App\Entity\SchedulingPreferences;
use App\Security\Scope;
use App\State\SchedulingPreferencesProcessor;
use App\State\SchedulingPreferencesProvider;

/**
 * What the meeting time finder keeps free besides the working hours and zone of the notification settings
 * (`/api/v1/notification-settings`): `bufferMinutes` on both sides of busy time, `travelMinutes` to and from busy time
 * at a place that does not name its own travel time, and nothing sooner than `minimumNoticeMinutes` from now. All are
 * 0 until the user sets them. `planTodos` (off until the user switches it on) gives open todos with a deadline and a
 * duration free time before their deadline on their own (`POST /api/v1/todos/{id}/plan` does it for one todo).
 */
#[ApiResource(
    shortName: 'SchedulingPreferences',
    routePrefix: '/api/v1',
    operations: [
        new Get(
            uriTemplate: '/calendar/scheduling-preferences',
            uriVariables: [],
            security: "is_granted('".Scope::ROLE_SETTINGS_READ."')",
            provider: SchedulingPreferencesProvider::class,
        ),
        new Patch(
            uriTemplate: '/calendar/scheduling-preferences',
            uriVariables: [],
            security: "is_granted('".Scope::ROLE_SETTINGS_WRITE."')",
            input: UpdateSchedulingPreferences::class,
            provider: SchedulingPreferencesProvider::class,
            processor: SchedulingPreferencesProcessor::class,
        ),
    ],
)]
final readonly class SchedulingPreferencesResource
{
    public function __construct(
        public int $bufferMinutes,
        public int $travelMinutes,
        public int $minimumNoticeMinutes,
        public bool $planTodos,
    ) {
    }

    public static function fromEntity(SchedulingPreferences $preferences): self
    {
        return new self($preferences->getBufferMinutes(), $preferences->getTravelMinutes(), $preferences->getMinimumNoticeMinutes(), $preferences->plansTodos());
    }
}
