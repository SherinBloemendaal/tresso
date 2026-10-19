<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\OAuthProviderInfo;
use App\Enum\OAuthProvider;
use App\Enum\ProviderFeature;
use App\ProviderAuth\ProviderSettings;

/**
 * @implements ProviderInterface<OAuthProviderInfo>
 */
final readonly class OAuthProviderInfoProvider implements ProviderInterface
{
    public function __construct(private ProviderSettings $settings)
    {
    }

    /**
     * @return list<OAuthProviderInfo>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map(
            static fn (OAuthProvider $provider): OAuthProviderInfo => new OAuthProviderInfo(
                $provider->value,
                $provider->label(),
                array_map(static fn (ProviderFeature $feature): string => $feature->value, ProviderFeature::cases()),
            ),
            $this->settings->configuredProviders(),
        );
    }
}
