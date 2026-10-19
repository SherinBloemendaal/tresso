<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Entity\User;
use App\State\MeProvider;

/**
 * The signed-in user. Only user tokens qualify; client-credentials tokens (sync workers) are refused.
 */
#[ApiResource(
    shortName: 'Me',
    routePrefix: '/api/v1',
    operations: [
        new Get(
            uriTemplate: '/me',
            uriVariables: [],
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            provider: MeProvider::class,
        ),
    ],
)]
final readonly class Me
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $email,
        public string $displayName,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return new self($user->getId()->toRfc4122(), $user->getEmail(), $user->getDisplayName(), $user->getCreatedAt());
    }
}
