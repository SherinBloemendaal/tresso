<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Security\Scope;
use App\State\WebPushKeyProvider;

/**
 * The application server key browsers subscribe to push with; null when this server has no Web Push keys.
 */
#[ApiResource(
    shortName: 'WebPushKey',
    routePrefix: '/api/v1',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/notification-devices/web-push-key', uriVariables: [], security: "is_granted('".Scope::ROLE_SETTINGS_READ."')", provider: WebPushKeyProvider::class),
    ],
)]
final readonly class WebPushKey
{
    public function __construct(public ?string $publicKey)
    {
    }
}
