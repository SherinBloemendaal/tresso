<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use App\Security\Scope;
use App\State\SyncChangesProvider;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Everything that changed for the signed-in user after a change number: the client's way to keep a local copy.
 *
 * Start with since=0, store the returned cursor, and call again with since=cursor while hasMore is true. A page never
 * splits the changes of one change number. "deleted" lists removed entities; a removed mail account means everything
 * under it (folders, threads, messages, outgoing messages) is gone too.
 */
#[ApiResource(
    shortName: 'SyncChanges',
    routePrefix: '/api/v1',
    operations: [
        new Get(
            uriTemplate: '/sync/changes',
            uriVariables: [],
            security: "is_granted('".Scope::ROLE_MAIL_READ."')",
            provider: SyncChangesProvider::class,
            normalizationContext: ['skip_null_values' => false],
            parameters: [
                'since' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                    description: 'The cursor from the previous response; 0 for a full sync.',
                    constraints: [new Assert\Regex('/^\d{1,18}$/', message: 'since must be a non-negative integer.')],
                ),
                'limit' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 2000, 'default' => 500],
                    description: 'Soft limit on entities per page; a page always contains complete change numbers.',
                    constraints: [new Assert\Regex('/^\d{1,4}$/', message: 'limit must be an integer.'), new Assert\Range(min: 1, max: 2000)],
                ),
            ],
        ),
    ],
)]
final readonly class SyncChanges
{
    /**
     * @param list<array<string, mixed>>                $mailAccounts
     * @param list<array<string, mixed>>                $folders
     * @param list<array<string, mixed>>                $threads
     * @param list<array<string, mixed>>                $messages
     * @param list<array<string, mixed>>                $outgoingMessages
     * @param list<array<string, mixed>>                $signatures
     * @param list<array<string, mixed>>                $drafts
     * @param list<array<string, mixed>>                $smartInboxes
     * @param list<array{type: string, id: string}> $deleted
     */
    public function __construct(
        public int $cursor,
        public bool $hasMore,
        public array $mailAccounts,
        public array $folders,
        public array $threads,
        public array $messages,
        public array $outgoingMessages,
        public array $signatures,
        public array $drafts,
        public array $smartInboxes,
        public array $deleted,
    ) {
    }
}
