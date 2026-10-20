<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Calendar\EventDraftInput;
use App\Calendar\Assistant\EventDraft;
use App\Security\Scope;
use App\State\EventDraftProcessor;

/**
 * Reads free text into an event draft for the editor; nothing is saved. Only where `GET /api/v1/calendar/capabilities`
 * reports `eventDrafts`: elsewhere the answer is 501.
 */
#[ApiResource(
    shortName: 'EventDraft',
    routePrefix: '/api/v1',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(
            uriTemplate: '/calendar/event-drafts',
            status: 200,
            security: "is_granted('".Scope::ROLE_CALENDAR_READ."')",
            input: EventDraftInput::class,
            output: EventDraft::class,
            read: false,
            processor: EventDraftProcessor::class,
        ),
    ],
)]
final readonly class EventDraftResource
{
}
