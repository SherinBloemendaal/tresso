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
use App\ApiResource\Input\SmartInbox\CreateSmartInbox;
use App\ApiResource\Input\SmartInbox\UpdateSmartInbox;
use App\Entity\Category;
use App\Security\Scope;
use App\State\SmartInboxProcessor;
use App\State\SmartInboxProvider;

/**
 * A smart inbox of the signed-in user: one of the five built-in ones (`builtin` says which) or one of their own. Mail
 * lands in exactly one smart inbox and gets its verdict. Every change reclassifies the last 30 days of mail; built-in
 * smart inboxes can be changed but not deleted.
 */
#[ApiResource(
    shortName: 'SmartInbox',
    routePrefix: '/api/v1',
    operations: [
        new GetCollection(
            uriTemplate: '/smart-inboxes',
            paginationEnabled: false,
            security: "is_granted('".Scope::ROLE_SETTINGS_READ."')",
            provider: SmartInboxProvider::class,
        ),
        new Get(
            uriTemplate: '/smart-inboxes/{id}',
            security: "is_granted('".Scope::ROLE_SETTINGS_READ."')",
            provider: SmartInboxProvider::class,
        ),
        new Post(
            uriTemplate: '/smart-inboxes',
            security: "is_granted('".Scope::ROLE_SETTINGS_WRITE."')",
            input: CreateSmartInbox::class,
            read: false,
            processor: SmartInboxProcessor::class,
        ),
        new Patch(
            uriTemplate: '/smart-inboxes/{id}',
            security: "is_granted('".Scope::ROLE_SETTINGS_WRITE."')",
            input: UpdateSmartInbox::class,
            provider: SmartInboxProvider::class,
            processor: SmartInboxProcessor::class,
        ),
        new Delete(
            uriTemplate: '/smart-inboxes/{id}',
            security: "is_granted('".Scope::ROLE_SETTINGS_WRITE."')",
            provider: SmartInboxProvider::class,
            processor: SmartInboxProcessor::class,
        ),
    ],
)]
final readonly class SmartInboxResource
{
    /**
     * @param list<string> $includeSenders
     * @param list<string> $excludeSenders
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        /** spam, notification, advertise, todo or informational; null for the user's own. */
        public ?string $builtin,
        public string $name,
        public string $instructions,
        public array $includeSenders,
        public array $excludeSenders,
        public string $verdict,
        /** Whether new mail judged `do` here becomes a todo; only with the `do` verdict. */
        public bool $autoCreateTodo,
        public int $position,
    ) {
    }

    public static function fromEntity(Category $smartInbox): self
    {
        return new self(
            $smartInbox->getId()->toRfc4122(),
            $smartInbox->getBuiltin()?->value,
            $smartInbox->getName(),
            $smartInbox->getInstructions(),
            $smartInbox->getIncludeSenders(),
            $smartInbox->getExcludeSenders(),
            $smartInbox->getVerdict()->value,
            $smartInbox->isAutoCreateTodo(),
            $smartInbox->getPosition(),
        );
    }
}
