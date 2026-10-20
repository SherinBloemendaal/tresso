<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Where the meeting time finder learns when attendees are busy: a provider's free/busy (Google, Microsoft Graph,
 * CalDAV scheduling), or what another edition knows. The open edition has none, so attendees' availability is unknown
 * and only the user's own time counts.
 */
#[AutoconfigureTag(self::TAG)]
interface AttendeeAvailabilitySource
{
    public const string TAG = 'app.calendar.attendee_availability_source';

    /**
     * Busy time in [$from, $to) of the attendees this source knows about; attendees it knows nothing of are left out
     * (an attendee it knows to be free has an empty list).
     *
     * @param list<string> $addresses lower case
     *
     * @return array<string, list<BusyTime>>
     */
    public function busyTimes(User $user, array $addresses, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
