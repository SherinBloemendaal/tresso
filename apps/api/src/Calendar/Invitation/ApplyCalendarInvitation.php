<?php

declare(strict_types=1);

namespace App\Calendar\Invitation;

/**
 * Apply a recorded invitation, update, cancellation or reply to the user's calendars.
 */
final readonly class ApplyCalendarInvitation
{
    public function __construct(public string $invitationId)
    {
    }
}
