<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\ApiResource\Output\CleanupSuggestionOutput;
use App\Security\Scope;
use App\State\CleanupSuggestionProvider;

/**
 * What to clean up, computed from fixed rules over the user's mail; nothing is guessed. Each suggestion's `kind` names
 * its rule:
 *
 * - `newsletter`: a sender of mailing-list mail (List-Unsubscribe or List-Id) with `unread` unread messages in the
 *   inboxes, none of whose mail the user opened in the last `windowDays` days.
 * - `unopenedSender`: the same for any other sender, with many more unread.
 * - `oldInSmartInbox`: conversations in the Notifications or Advertising smart inbox older than `olderThanDays`.
 * - `largeOld`: conversations with a message of `largerThan` bytes or more, older than `olderThanDays`, `bytes` in all.
 *
 * `action` and `scope` are the bulk action that cleans it up: POST them to `/bulk-actions` as they are.
 */
#[ApiResource(
    shortName: 'CleanupSuggestions',
    routePrefix: '/api/v1',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/cleanup-suggestions',
            uriVariables: [],
            security: "is_granted('".Scope::ROLE_MAIL_READ."')",
            provider: CleanupSuggestionProvider::class,
        ),
    ],
)]
final readonly class CleanupSuggestionList
{
    /**
     * @param list<CleanupSuggestionOutput> $suggestions senders first, then old mail in smart inboxes, then large mail
     */
    public function __construct(public array $suggestions)
    {
    }
}
