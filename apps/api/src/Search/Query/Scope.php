<?php

declare(strict_types=1);

namespace App\Search\Query;

/**
 * What an in: value resolved to: some of the user's folders, or some of their accounts.
 */
final readonly class Scope
{
    /**
     * @param 'folderIds'|'accountId' $field
     * @param non-empty-list<string>  $ids
     */
    private function __construct(
        public string $field,
        public array $ids,
    ) {
    }

    /**
     * @param non-empty-list<string> $folderIds
     */
    public static function folders(array $folderIds): self
    {
        return new self('folderIds', $folderIds);
    }

    /**
     * @param non-empty-list<string> $accountIds
     */
    public static function accounts(array $accountIds): self
    {
        return new self('accountId', $accountIds);
    }
}
