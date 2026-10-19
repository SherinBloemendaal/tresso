<?php

declare(strict_types=1);

namespace App\Sync\Change;

use App\Entity\User;
use Symfony\Component\Uid\Uuid;

/**
 * An entity clients keep a local copy of. Every insert or update gets the owner's next change number; deletes leave a
 * tombstone. Clients fetch everything above the number they last saw.
 */
interface TracksChanges
{
    public function getId(): Uuid;

    /** The user whose clients see this entity. */
    public function getChangeOwner(): User;

    /**
     * Fields clients see. An update that only touches other fields (sync cursors, leases, encrypted credentials) is
     * not a change for clients.
     *
     * @return list<string>|null null when every field counts
     */
    public static function clientFields(): ?array;

    /** Type name in the delta sync response and in tombstones. */
    public static function changeType(): string;

    public function getChangeSeq(): int;

    public function setChangeSeq(int $changeSeq): void;
}
