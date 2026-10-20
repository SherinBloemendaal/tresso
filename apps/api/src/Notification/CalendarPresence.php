<?php

declare(strict_types=1);

namespace App\Notification;

use App\ApiResource\CalendarOccurrence;
use App\Calendar\OccurrenceExpander;
use App\Entity\User;

/**
 * What the user's calendar says they are doing right now: in a focus block, in a meeting, or in another busy event.
 * Notifications wait for the end of these or come silently.
 */
final readonly class CalendarPresence
{
    public function __construct(private OccurrenceExpander $occurrences)
    {
    }

    /**
     * The end of the focus block the user is in now (busy, only them, titled "Focus ..."), or null. The event a
     * notification is about ($exceptEventId) does not count.
     */
    public function focusBlockEnd(User $user, \DateTimeImmutable $now, ?string $exceptEventId = null): ?\DateTimeImmutable
    {
        foreach ($this->current($user, $now, $exceptEventId) as $occurrence) {
            if (0 !== $occurrence->attendeeCount || !str_starts_with(mb_strtolower(trim($occurrence->summary)), 'focus') || !isset($occurrence->end['dateTime'])) {
                continue;
            }

            return new \DateTimeImmutable($occurrence->end['dateTime']);
        }

        return null;
    }

    /** Whether the user is in another busy event they take part in right now. */
    public function inBusyEvent(User $user, \DateTimeImmutable $now, ?string $exceptEventId = null): bool
    {
        foreach ($this->current($user, $now, $exceptEventId) as $occurrence) {
            if (!\in_array($occurrence->selfResponse, ['declined', 'needs-action'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The end of the meeting the user is in now (busy, with other people, not declined or unanswered), or null. When
     * meetings overlap, the one that ends last.
     */
    public function meetingEnd(User $user, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $end = null;
        foreach ($this->current($user, $now, null) as $occurrence) {
            if (0 === $occurrence->attendeeCount || \in_array($occurrence->selfResponse, ['declined', 'needs-action'], true) || !isset($occurrence->end['dateTime'])) {
                continue;
            }
            $ends = new \DateTimeImmutable($occurrence->end['dateTime']);
            $end = null === $end ? $ends : max($end, $ends);
        }

        return $end;
    }

    /**
     * The timed, busy, not cancelled occurrences under way now.
     *
     * @return iterable<CalendarOccurrence>
     */
    private function current(User $user, \DateTimeImmutable $now, ?string $exceptEventId): iterable
    {
        foreach ($this->occurrences->expand($user, [], $now, $now->modify('+1 minute'), new \DateTimeZone('UTC')) as $occurrence) {
            if ($occurrence->eventId === $exceptEventId || $occurrence->allDay || 'busy' !== $occurrence->transparency || 'cancelled' === $occurrence->status) {
                continue;
            }
            yield $occurrence;
        }
    }
}
