<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Calendar\MeetingTimesInput;
use App\ApiResource\Output\ProposedSlot;
use App\Security\Scope;
use App\State\MeetingTimesProcessor;

/**
 * Proposes times for a meeting (or a todo) of `durationMinutes` in a window. Deterministic: the same calendars,
 * preferences and moment give the same slots in the same order.
 *
 * Slots lie in the user's working hours (unless `workingHoursOnly` is false), outside focus blocks, and keep the
 * buffer, or travel time for busy time at a place, around the user's busy time: events marked busy, not cancelled and
 * not declined, recurrence expanded, all-day events taking their whole days in `timeZone`. They start on multiples of
 * `stepMinutes` on the user's clock, do not overlap each other, and come best first: the fewest optional attendees
 * busy, then the earliest. Each slot says what closes its free time on either side (`freeFrom`, `freeUntil`), so a
 * client can explain it. Required attendees whose availability is known close their busy time too;
 * `attendees` says per address whether it was known. With no slots, `noSlotReason` says why.
 */
#[ApiResource(
    shortName: 'MeetingTimes',
    routePrefix: '/api/v1',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(
            uriTemplate: '/calendar/meeting-times',
            status: 200,
            security: "is_granted('".Scope::ROLE_CALENDAR_READ."')",
            input: MeetingTimesInput::class,
            read: false,
            processor: MeetingTimesProcessor::class,
        ),
    ],
)]
final readonly class MeetingTimesResource
{
    /**
     * @param list<ProposedSlot>                                $slots     best first
     * @param list<array{address: string, availability: string}> $attendees `known` or `unknown`
     */
    public function __construct(
        public array $slots,
        /** window_passed, no_working_hours or no_free_time; null when there are slots. */
        public ?string $noSlotReason,
        /** The zone working hours and all-day events were read in. */
        public string $timeZone,
        public array $attendees,
    ) {
    }
}
