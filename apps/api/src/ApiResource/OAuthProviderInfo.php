<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Security\Scope;
use App\State\OAuthProviderInfoProvider;

/**
 * The providers users can sign in with on this server (those the operator registered an OAuth app for), and what
 * each can be connected for.
 */
#[ApiResource(
    shortName: 'OAuthProvider',
    routePrefix: '/api/v1',
    operations: [
        new GetCollection(
            uriTemplate: '/oauth-providers',
            paginationEnabled: false,
            security: "is_granted('".Scope::ROLE_SETTINGS_READ."')",
            provider: OAuthProviderInfoProvider::class,
        ),
    ],
)]
final readonly class OAuthProviderInfo
{
    /**
     * @param list<string> $features {@see \App\Enum\ProviderFeature} values
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $name,
        public array $features,
    ) {
    }
}
