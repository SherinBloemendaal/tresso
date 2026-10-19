<?php

declare(strict_types=1);

namespace App\Sync\Calendar;

use App\Entity\CalendarOperation;
use App\Enum\CalendarOperationKind;

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

    /**
     * A put is sent as the event is now: its current text, and the resource and etag of its last write, which may have
     * finished after this put was queued.
     */
    public static function fromEntity(CalendarOperation $operation): self
    {
        $object = $operation->getObject();
        $put = CalendarOperationKind::Put === $operation->getKind() && null !== $object;

        return new self(
            $operation->getId()->toRfc4122(),
            $operation->getKind()->value,
            (string) $operation->getCalendar()->getRemoteId(),
            $operation->getUid(),
            $put ? $object->getRemoteId() : $operation->getRemoteId(),
            $put ? $object->getEtag() : $operation->getIfMatch(),
            $object?->getIcal(),
            $operation->getAttempts(),
        );
    }
}
