<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\CreateSignature;
use App\ApiResource\Input\UpdateSignature;
use App\Entity\MailAccount;
use App\Entity\Signature;
use App\Security\Scope;
use App\State\SignatureProcessor;
use App\State\SignatureProvider;

/**
 * An HTML signature of the signed-in user, already sanitized. Each mail account has at most one default signature;
 * "defaultFor" lists the accounts that use this one.
 */
#[ApiResource(
    shortName: 'Signature',
    routePrefix: '/api/v1',
    operations: [
        new GetCollection(
            uriTemplate: '/signatures',
            paginationEnabled: false,
            security: "is_granted('".Scope::ROLE_SIGNATURES_READ."')",
            provider: SignatureProvider::class,
        ),
        new Get(
            uriTemplate: '/signatures/{id}',
            security: "is_granted('".Scope::ROLE_SIGNATURES_READ."')",
            provider: SignatureProvider::class,
        ),
        new Post(
            uriTemplate: '/signatures',
            security: "is_granted('".Scope::ROLE_SIGNATURES_WRITE."')",
            input: CreateSignature::class,
            read: false,
            processor: SignatureProcessor::class,
        ),
        new Patch(
            uriTemplate: '/signatures/{id}',
            security: "is_granted('".Scope::ROLE_SIGNATURES_WRITE."')",
            input: UpdateSignature::class,
            provider: SignatureProvider::class,
            processor: SignatureProcessor::class,
        ),
        new Delete(
            uriTemplate: '/signatures/{id}',
            security: "is_granted('".Scope::ROLE_SIGNATURES_WRITE."')",
            provider: SignatureProvider::class,
            processor: SignatureProcessor::class,
        ),
    ],
)]
final class SignatureResource
{
    /**
     * @param list<string> $defaultFor ids of the mail accounts that use this signature by default
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $name,
        public string $html,
        public array $defaultFor,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param list<MailAccount> $accounts the owner's accounts; those using this signature end up in "defaultFor"
     */
    public static function fromEntity(Signature $signature, array $accounts): self
    {
        $defaultFor = array_values(array_map(
            static fn (MailAccount $account): string => $account->getId()->toRfc4122(),
            array_filter($accounts, static fn (MailAccount $account): bool => $account->getDefaultSignature() === $signature),
        ));

        return new self(
            $signature->getId()->toRfc4122(),
            $signature->getName(),
            $signature->getHtml(),
            $defaultFor,
            $signature->getUpdatedAt(),
        );
    }
}
