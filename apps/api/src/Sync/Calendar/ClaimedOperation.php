<?php

declare(strict_types=1);

namespace App\Sync\Calendar;

use App\Entity\CalendarOperation;

/**
 * A calendar write the worker makes now: put the iCalendar text (create when remoteId is null, else update with
 * If-Match ifMatch), or delete remoteId.
 */
final readonly class ClaimedOperation
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $calendarRemoteId,
        public string $uid,
        public ?string $remoteId,
        public ?string $ifMatch,
        public ?string $ical,
        public int $attempt,
    ) {
    }

    public static function fromEntity(CalendarOperation $operation): self
    {
        return new self(
            $operation->getId()->toRfc4122(),
            $operation->getKind()->value,
            (string) $operation->getCalendar()->getRemoteId(),
            $operation->getUid(),
            $operation->getRemoteId(),
            $operation->getIfMatch(),
            $operation->getObject()?->getIcal(),
            $operation->getAttempts(),
        );
    }
}
