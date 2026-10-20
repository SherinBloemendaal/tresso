<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

/**
 * An event found in a message, to offer next to it.
 */
final readonly class ExtractedEvent
{
    public function __construct(
        public EventDraft $draft,
        /** `calendar_part` for a text/calendar part of the message, `text` for an event read from its words. */
        public string $source,
        /** The iCalendar UID, for events from a calendar part. */
        public ?string $uid = null,
        /** The event in the user's calendar with this UID, when there is one: offer to open it rather than add it. */
        public ?string $eventId = null,
    ) {
    }
}
