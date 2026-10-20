<?php

declare(strict_types=1);

namespace App\Scan;

/**
 * The order in which a verdict marks drafts and messages changed for their owners: owners by id, and per owner drafts
 * before messages, ids sorted. Every verdict takes owners' change counters in the same order, so two of them never
 * deadlock on each other.
 */
final class ScanTouches
{
    /**
     * @param list<array{id: string, owner_id: string}> $drafts
     * @param list<array{id: string, owner_id: string}> $messages
     *
     * @return list<array{'draft'|'message', string, list<string>}> kind, owner id, entity ids
     */
    public static function plan(array $drafts, array $messages): array
    {
        $byOwner = [];
        foreach (['draft' => $drafts, 'message' => $messages] as $kind => $rows) {
            foreach ($rows as $row) {
                $byOwner[$row['owner_id']][$kind][] = $row['id'];
            }
        }
        ksort($byOwner, \SORT_STRING);

        $plan = [];
        foreach ($byOwner as $ownerId => $kinds) {
            foreach (['draft', 'message'] as $kind) {
                $ids = array_values(array_unique($kinds[$kind] ?? []));
                if ([] === $ids) {
                    continue;
                }
                sort($ids, \SORT_STRING);
                $plan[] = [$kind, (string) $ownerId, $ids];
            }
        }

        return $plan;
    }
}
