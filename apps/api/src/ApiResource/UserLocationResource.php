<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Put;
use App\ApiResource\Input\Notification\LocationInput;
use App\Security\Scope;
use App\State\UserLocationProcessor;

/**
 * The user's last known position for leave-now reminders, sent by a device only when the user allows it. Stored
 * rounded to about 100 m; DELETE forgets it.
 */
#[ApiResource(
    shortName: 'UserLocation',
    routePrefix: '/api/v1',
    operations: [
        new Put(uriTemplate: '/me/location', uriVariables: [], status: 200, security: "is_granted('".Scope::ROLE_SETTINGS_WRITE."')", input: LocationInput::class, read: false, processor: UserLocationProcessor::class, allowCreate: true),
        new Delete(uriTemplate: '/me/location', uriVariables: [], security: "is_granted('".Scope::ROLE_SETTINGS_WRITE."')", read: false, processor: UserLocationProcessor::class),
    ],
)]
final readonly class UserLocationResource
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public \DateTimeImmutable $recordedAt,
    ) {
    }
}
