<?php

declare(strict_types=1);

namespace App\Search\Query;

use App\Search\AddressTokens;
use App\Search\MessageIndex;

/**
 * Turns a parsed query into Meilisearch filter expressions, all of which must hold.
 *
 * The first two are what keeps users apart in the shared index: the owner, and the accounts the owner has right now
 * (so mail of a just removed account is gone before its documents are). Every value the user typed is quoted;
 * folders and accounts arrive as ids that were looked up among the owner's own.
 */
final class SearchFilter
{
    /**
     * @param non-empty-list<string> $accountIds the owner's accounts
     * @param list<Scope>            $scopes     what each in: value resolved to
     *
     * @return list<string>
     */
    public static function build(string $ownerId, array $accountIds, SearchQuery $query, array $scopes): array
    {
        $filters = [
            'ownerId = '.MessageIndex::quote($ownerId),
            'accountId IN '.self::list($accountIds),
        ];

        foreach ($query->from as $value) {
            foreach (AddressTokens::forQuery($value) as $token) {
                $filters[] = 'fromTokens = '.MessageIndex::quote($token);
            }
        }
        foreach ($query->to as $value) {
            foreach (AddressTokens::forQuery($value) as $token) {
                $filters[] = 'toTokens = '.MessageIndex::quote($token);
            }
        }
        foreach ($scopes as $scope) {
            $filters[] = $scope->field.' IN '.self::list($scope->ids);
        }
        if ($query->hasAttachment) {
            $filters[] = 'hasAttachments = true';
        }
        if (null !== $query->after) {
            $filters[] = 'receivedAt >= '.$query->after->getTimestamp();
        }
        if (null !== $query->before) {
            $filters[] = 'receivedAt < '.$query->before->getTimestamp();
        }

        return $filters;
    }

    /**
     * @param non-empty-list<string> $values
     */
    private static function list(array $values): string
    {
        return '['.implode(', ', array_map(MessageIndex::quote(...), $values)).']';
    }
}
