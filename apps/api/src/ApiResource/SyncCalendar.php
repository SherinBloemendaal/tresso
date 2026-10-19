<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Sync\Calendar\CalendarListInput;
use App\ApiResource\Input\Sync\Calendar\CalendarStatusInput;
use App\ApiResource\Input\Sync\Calendar\InventoryInput;
use App\ApiResource\Input\Sync\Calendar\ObjectBatchInput;
use App\ApiResource\Input\Sync\Calendar\OperationResultInput;
use App\ApiResource\Input\Sync\OutboxClaimInput;
use App\Security\Scope;
use App\State\SyncCalendarProcessor;
use App\Sync\Calendar\CalendarListResult;
use App\Sync\Calendar\CalendarStatusResult;
use App\Sync\Calendar\ClaimResult;
use App\Sync\Calendar\InventoryResult;
use App\Sync\Calendar\ObjectBatchResult;
use App\Sync\Calendar\OperationResult;

/**
 * Calendar sync, as seen by the worker that leases an account: report the calendars and their resources, take the
 * writes Tresso has for the provider, and report each one. Internal (scope sync:ingest), left out of the API docs.
 */
#[ApiResource(
    shortName: 'SyncCalendar',
    routePrefix: '/internal',
    operations: [
        new Post(
            uriTemplate: '/sync/accounts/{accountId}/calendars',
            status: 200,
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_INGEST."')",
            input: CalendarListInput::class,
            output: CalendarListResult::class,
            normalizationContext: ['skip_null_values' => false],
            read: false,
            processor: SyncCalendarProcessor::class,
            name: 'internal_sync_calendars',
        ),
        new Post(
            uriTemplate: '/sync/accounts/{accountId}/calendar-status',
            status: 200,
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_INGEST."')",
            input: CalendarStatusInput::class,
            output: CalendarStatusResult::class,
            read: false,
            processor: SyncCalendarProcessor::class,
            name: 'internal_sync_calendar_status',
        ),
        new Post(
            uriTemplate: '/sync/accounts/{accountId}/calendars/{calendarId}/inventory',
            status: 200,
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_INGEST."')",
            input: InventoryInput::class,
            output: InventoryResult::class,
            read: false,
            processor: SyncCalendarProcessor::class,
            name: 'internal_sync_calendar_inventory',
        ),
        new Post(
            uriTemplate: '/sync/accounts/{accountId}/calendars/{calendarId}/objects',
            status: 200,
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_INGEST."')",
            input: ObjectBatchInput::class,
            output: ObjectBatchResult::class,
            read: false,
            processor: SyncCalendarProcessor::class,
            name: 'internal_sync_calendar_objects',
        ),
        new Post(
            uriTemplate: '/sync/accounts/{accountId}/calendar-operations/claim',
            status: 200,
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_INGEST."')",
            input: OutboxClaimInput::class,
            output: ClaimResult::class,
            normalizationContext: ['skip_null_values' => false],
            read: false,
            processor: SyncCalendarProcessor::class,
            name: 'internal_sync_calendar_claim',
        ),
        new Post(
            uriTemplate: '/sync/accounts/{accountId}/calendar-operations/{operationId}/result',
            status: 200,
            openapi: false,
            security: "is_granted('".Scope::ROLE_SYNC_INGEST."')",
            input: OperationResultInput::class,
            output: OperationResult::class,
            read: false,
            processor: SyncCalendarProcessor::class,
            name: 'internal_sync_calendar_result',
        ),
    ],
)]
final class SyncCalendar
{
}
