<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Notification\LeaveEstimateInput;
use App\Security\Scope;
use App\State\LeaveEstimateProcessor;

/**
 * A leave-now estimate from the phone's position. The phone sends it from an hour before an event with a place, and
 * when the server asks for one; the leave-now reminder then moves to the estimated moment. The position is never
 * stored.
 */
#[ApiResource(
    shortName: 'LeaveEstimate',
    routePrefix: '/api/v1',
    operations: [
        new Post(uriTemplate: '/calendar-events/{id}/leave-estimate', status: 200, security: "is_granted('".Scope::ROLE_CALENDAR_WRITE."')", input: LeaveEstimateInput::class, read: false, processor: LeaveEstimateProcessor::class),
    ],
)]
final readonly class LeaveEstimateResource
{
    public function __construct(
        /** Travel minutes, or null when the place cannot be reached by the user's way of travel. */
        public ?int $minutes,
        /** When to leave, including the extra time; null without an estimate. */
        public ?\DateTimeImmutable $leaveAt,
    ) {
    }
}
