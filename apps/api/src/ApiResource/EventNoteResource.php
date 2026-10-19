<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Put;
use App\ApiResource\Input\Calendar\EventNoteInput;
use App\Security\Scope;
use App\State\EventNoteProcessor;

/**
 * The user's private note on an event or one occurrence. Notes reach clients through the delta as `eventNotes`.
 */
#[ApiResource(
    shortName: 'EventNote',
    routePrefix: '/api/v1',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Put(uriTemplate: '/calendar-events/{id}/note', status: 200, security: "is_granted('".Scope::ROLE_CALENDAR_WRITE."')", input: EventNoteInput::class, read: false, processor: EventNoteProcessor::class, allowCreate: true),
    ],
)]
final readonly class EventNoteResource
{
    public function __construct(
        /** The event's id: a note is addressed through its event. */
        #[ApiProperty(identifier: true)]
        public string $id,
        public ?string $recurrenceId,
        public string $text,
    ) {
    }
}
