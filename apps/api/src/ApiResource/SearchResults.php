<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use App\ApiResource\Output\SearchHit;
use App\Security\Scope;
use App\State\SearchResultsProvider;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Searches the signed-in user's mail, newest first.
 *
 * `q` takes words, "quoted phrases" and -excluded words, combined with filters: from:, to: (To, Cc and Bcc; an
 * address, a domain or a name, quoted when it has spaces), has:attachment, in: (a folder role such as inbox, sent or
 * archive, an account's address or name, or a folder name), after: (on or after a day) and before: (before a day),
 * with days as 2026-10-01 in `timeZone`. Every word and filter must match. A filter that is written wrongly is
 * rejected with a message on `q`; an in: that names nothing finds nothing.
 *
 * Results are paged; `totalHits` is exact up to 1000. Mail shows up in search a moment after it arrives.
 */
#[ApiResource(
    shortName: 'Search',
    routePrefix: '/api/v1',
    operations: [
        new Get(
            uriTemplate: '/search',
            uriVariables: [],
            security: "is_granted('".Scope::ROLE_MAIL_READ."')",
            provider: SearchResultsProvider::class,
            normalizationContext: ['skip_null_values' => false],
            parameters: [
                'q' => new QueryParameter(
                    schema: ['type' => 'string', 'maxLength' => 1000],
                    description: 'What to look for, with optional filters.',
                    required: true,
                    constraints: [new Assert\NotBlank(message: 'Type something to search for.'), new Assert\Length(max: 1000)],
                ),
                'page' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                    constraints: [new Assert\Regex('/^\d{1,4}$/', message: 'page must be a positive integer.'), new Assert\Range(min: 1)],
                ),
                'perPage' => new QueryParameter(
                    schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
                    constraints: [new Assert\Regex('/^\d{1,2}$/', message: 'perPage must be an integer.'), new Assert\Range(min: 1, max: 50)],
                ),
                'timeZone' => new QueryParameter(
                    schema: ['type' => 'string', 'default' => 'UTC'],
                    description: 'IANA time zone for after: and before:, e.g. Europe/Amsterdam.',
                    constraints: [new Assert\Timezone()],
                ),
            ],
        ),
    ],
)]
final readonly class SearchResults
{
    /**
     * @param list<SearchHit> $messages
     */
    public function __construct(
        public string $query,
        public int $page,
        public int $perPage,
        public int $totalHits,
        public int $totalPages,
        public array $messages,
    ) {
    }
}
