<?php

declare(strict_types=1);

namespace App\Calendar\Invitation;

use App\Calendar\ICalendar\CalendarDocument;
use App\Calendar\ICalendar\InvalidCalendarData;
use App\Calendar\ICalendar\Props;
use App\Entity\CalendarInvitation;
use App\Entity\Message;
use App\Enum\ItipMethod;
use App\Realtime\AfterCommitDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Records the calendar objects that came with a new message, at ingest, and has them applied to the user's calendars
 * once the ingest is committed (when $apply says the message may change the calendar). Parts that are not usable iTIP (no METHOD, no event, broken) are skipped: a message
 * must never fail to ingest because of its calendar part.
 */
final readonly class InvitationRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AfterCommitDispatcher $afterCommit,
        private LoggerInterface $logger,
    ) {
    }

    public function record(Message $message, ?string $partMethod, string $ical, bool $apply): ?CalendarInvitation
    {
        try {
            $calendar = CalendarDocument::parse($ical);
        } catch (InvalidCalendarData $e) {
            $this->logger->info('Skipping an unreadable calendar part', ['message' => $message->getId()->toRfc4122(), 'error' => $e->getMessage()]);

            return null;
        }

        $method = ItipMethod::tryFrom(strtoupper(Props::text($calendar, 'METHOD') ?? trim((string) $partMethod)));
        $groups = CalendarDocument::eventsByUid($calendar);
        if (null === $method || 1 !== \count($groups)) {
            return null;
        }
        $uid = (string) array_key_first($groups);
        $events = $groups[$uid];
        $sequence = 0;
        $recurrenceKey = '';
        $hasMaster = false;
        foreach ($events as $event) {
            $sequence = max($sequence, Props::int($event, 'SEQUENCE'));
            $key = CalendarDocument::recurrenceKey($event);
            if ('' === $key) {
                $hasMaster = true;
                continue;
            }
            $recurrenceKey = '' === $recurrenceKey ? $key : $recurrenceKey;
        }

        $invitation = new CalendarInvitation($message, $method, $uid, $sequence, $hasMaster ? '' : $recurrenceKey, $calendar->serialize());
        $this->entityManager->persist($invitation);
        if ($apply) {
            $this->afterCommit->dispatch(new ApplyCalendarInvitation($invitation->getId()->toRfc4122()));
        }

        return $invitation;
    }
}
