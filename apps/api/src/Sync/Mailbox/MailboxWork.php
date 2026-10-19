<?php

declare(strict_types=1);

namespace App\Sync\Mailbox;

/**
 * Changes a worker must make on the mail server, in this order. A flag change sets `seen` and/or `flagged` (null
 * leaves a flag alone) on every copy in `locations`; a move moves the copy in `source` to the folder in `target`.
 * Folders are given by their name on the server; a uid only counts while the folder's UIDVALIDITY is `uidValidity`.
 */
final readonly class MailboxWork
{
    /**
     * @param list<array{id: string, kind: string, seen: ?bool, flagged: ?bool, locations: list<array{folder: string, uidValidity: ?int, remoteUid: string}>, source: ?array{folder: string, uidValidity: ?int, remoteUid: string}, target: ?array{folder: string}}> $changes
     */
    public function __construct(public array $changes)
    {
    }
}
