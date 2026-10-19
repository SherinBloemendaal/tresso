<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\SenderRule;
use App\Security\Scope;
use App\State\SenderRuleProcessor;
use App\State\SenderRuleProvider;

/**
 * A correction the user made, remembered for a sender: their mail goes to this smart inbox, with this verdict when
 * one is set. Rules are made by correcting a conversation (PUT /threads/{id}/classification); deleting one lets the
 * rules decide again for recent mail.
 */
#[ApiResource(
    shortName: 'SenderRule',
    routePrefix: '/api/v1',
    operations: [
        new GetCollection(
            uriTemplate: '/sender-rules',
            paginationEnabled: false,
            security: "is_granted('".Scope::ROLE_SETTINGS_READ."')",
            provider: SenderRuleProvider::class,
        ),
        new Delete(
            uriTemplate: '/sender-rules/{id}',
            security: "is_granted('".Scope::ROLE_SETTINGS_WRITE."')",
            provider: SenderRuleProvider::class,
            processor: SenderRuleProcessor::class,
        ),
    ],
)]
final readonly class SenderRuleResource
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        /** An address, or `@domain`. */
        public string $sender,
        public string $smartInboxId,
        /** null: the smart inbox's verdict. */
        public ?string $verdict,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromEntity(SenderRule $rule): self
    {
        return new self(
            $rule->getId()->toRfc4122(),
            $rule->getSender(),
            $rule->getCategory()->getId()->toRfc4122(),
            $rule->getVerdict()?->value,
            $rule->getUpdatedAt(),
        );
    }
}
