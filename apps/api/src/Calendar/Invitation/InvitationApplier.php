<?php

declare(strict_types=1);

namespace App\Calendar\Invitation;

use App\Calendar\CalendarStore;
use App\Calendar\ICalendar\CalendarDocument;
use App\Calendar\ICalendar\InvalidCalendarData;
use App\Calendar\ICalendar\Occurrences;
use App\Calendar\ICalendar\Props;
use App\Calendar\LocalCalendars;
use App\Calendar\SelfAddresses;
use App\Entity\Calendar;
use App\Entity\CalendarInvitation;
use App\Entity\CalendarObject;
use App\Entity\User;
use App\Enum\ItipMethod;
use App\Repository\CalendarObjectRepository;
use App\Repository\CalendarRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\ITip\Broker;
use Sabre\VObject\ITip\Message as ItipMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Applies calendar mail to the user's calendars (RFC 5546 from the receiving side):
 *
 * - REQUEST: a new or updated invitation for the user lands in the account's default calendar (or the Tresso
 *   calendar), unanswered. Updates older than what is stored are ignored.
 * - CANCEL: the event, or the cancelled occurrences, become cancelled.
 * - REPLY: an attendee's answer to an event the user organizes.
 *
 * Anyone can send calendar mail, so a message only counts when it comes from the right person: a REQUEST or CANCEL
 * from the event's organizer (as stored, or as the message says for an event that is new), a REPLY from an attendee
 * the event already has. A message never changes an event the user organizes, never changes who organizes an event,
 * and never raises SEQUENCE by more than MAX_SEQUENCE_STEP at once, so a forged high SEQUENCE cannot lock out the
 * organizer's later updates.
 *
 * Calendars whose provider schedules by itself (CalDAV auto-schedule, Google, Microsoft) get invitations from the
 * provider; Tresso never writes those events, so the same event is not there twice.
 */
#[AsMessageHandler]
final readonly class InvitationApplier
{
    public const int MAX_SEQUENCE_STEP = 100;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CalendarObjectRepository $objects,
        private CalendarRepository $calendars,
        private LocalCalendars $localCalendars,
        private CalendarStore $store,
        private SelfAddresses $selfAddresses,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ApplyCalendarInvitation $command): void
    {
        $this->entityManager->wrapInTransaction(function () use ($command): void {
            $invitation = Uuid::isValid($command->invitationId) ? $this->entityManager->find(CalendarInvitation::class, Uuid::fromString($command->invitationId)) : null;
            if (null === $invitation || null !== $invitation->getAppliedAt()) {
                return;
            }
            $this->applyNow($invitation);
        });
        $this->entityManager->clear();
    }

    /**
     * Applies calendar mail now, in the caller's transaction, with every check, and marks it applied whatever the
     * checks decided, so it is never applied twice.
     */
    public function applyNow(CalendarInvitation $invitation): void
    {
        try {
            $this->apply($invitation);
        } catch (InvalidCalendarData $e) {
            $this->logger->info('Calendar mail could not be applied', ['invitation' => $invitation->getId()->toRfc4122(), 'error' => $e->getMessage()]);
        }
        $invitation->markApplied(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    private function apply(CalendarInvitation $invitation): void
    {
        $account = $invitation->getMessage()->getMailAccount();
        $user = $account->getOwner();
        $self = $this->selfAddresses->of($user);
        $itip = CalendarDocument::forUid(CalendarDocument::parse($invitation->getIcal()), $invitation->getUid());

        $existing = $this->objects->findForOwnerByUid($user, $invitation->getUid());
        foreach ($existing as $object) {
            if ($object->getCalendar()->providerSchedules()) {
                return;
            }
        }
        $object = $existing[0] ?? null;
        $sender = $invitation->getMessage()->getFromAddress();

        match ($invitation->getMethod()) {
            ItipMethod::Request => $this->request($invitation, $itip, $object, $user, $self, $sender),
            ItipMethod::Cancel => $this->cancel($invitation, $itip, $object, $self, $sender),
            ItipMethod::Reply => $this->reply($invitation, $itip, $object, $self, $sender),
            default => null,
        };
    }

    /**
     * Whether a REQUEST or CANCEL may change the stored event: it comes from the stored organizer, says the same
     * organizer, and the event is not the user's own. Logs why not.
     *
     * @param list<string> $self
     */
    private function fromOrganizer(CalendarInvitation $invitation, VCalendar $itip, VCalendar $current, array $self, string $sender): bool
    {
        $organizer = self::organizer($current);
        $reason = match (true) {
            null === $organizer => 'the stored event has no organizer, so it is the user\'s own',
            \in_array($organizer, $self, true) => 'the user organizes the stored event',
            !self::sentBy($itip, $organizer, $sender) => 'the sender is not the stored organizer',
            $invitation->getSequence() > self::sequence($current, '') + self::MAX_SEQUENCE_STEP => 'SEQUENCE jumps too far',
            default => null,
        };
        if (null === $reason) {
            return true;
        }
        $this->logger->info('Ignoring calendar mail', ['invitation' => $invitation->getId()->toRfc4122(), 'reason' => $reason]);

        return false;
    }

    /**
     * @param list<string> $self
     */
    private function request(CalendarInvitation $invitation, VCalendar $itip, ?CalendarObject $object, User $user, array $self, string $sender): void
    {
        if (!self::invites($itip, $self)) {
            return;
        }

        if (null === $object) {
            $organizer = self::organizer($itip);
            if (null === $organizer || !self::sentBy($itip, $organizer, $sender)) {
                $this->logger->info('Ignoring calendar mail', ['invitation' => $invitation->getId()->toRfc4122(), 'reason' => 'the sender is not the organizer']);

                return;
            }
            $calendar = $this->targetCalendar($invitation, $user);
            if (null === $calendar) {
                return;
            }
            $rows = $this->store->save(new CalendarObject($calendar, $invitation->getUid(), ''), $itip);
            foreach ($rows as $row) {
                $row->setMessage($invitation->getMessage());
            }
            $this->entityManager->flush();

            return;
        }

        $current = CalendarDocument::parse($object->getIcal());
        if (!$this->fromOrganizer($invitation, $itip, $current, $self, $sender)) {
            return;
        }
        if ($invitation->getSequence() < self::sequence($current, $invitation->getRecurrenceKey())) {
            return;
        }
        if ('' === $invitation->getRecurrenceKey() || null === CalendarDocument::master($current)) {
            $updated = $itip;
        } else {
            // An update of single occurrences: only those overrides change, the series stays.
            $updated = $current;
            foreach (CalendarDocument::events($itip) as $override) {
                $key = CalendarDocument::recurrenceKey($override);
                if ('' === $key) {
                    continue;
                }
                $old = CalendarDocument::override($updated, $key);
                if (null !== $old) {
                    $updated->remove($old);
                }
                $updated->add(clone $override);
            }
        }
        $rows = $this->store->save($object, $updated);
        foreach ($rows as $row) {
            $row->setMessage($invitation->getMessage());
        }
        $this->entityManager->flush();
    }

    /**
     * @param list<string> $self
     */
    private function cancel(CalendarInvitation $invitation, VCalendar $itip, ?CalendarObject $object, array $self, string $sender): void
    {
        if (null === $object) {
            return;
        }
        $current = CalendarDocument::parse($object->getIcal());
        if (!$this->fromOrganizer($invitation, $itip, $current, $self, $sender)) {
            return;
        }
        if ($invitation->getSequence() < self::sequence($current, '')) {
            return;
        }

        if ('' === $invitation->getRecurrenceKey()) {
            $cancelled = CalendarDocument::events($current);
        } else {
            $master = CalendarDocument::master($current);
            $cancelled = [];
            foreach (CalendarDocument::events($itip) as $event) {
                $key = CalendarDocument::recurrenceKey($event);
                if ('' === $key) {
                    continue;
                }
                $override = CalendarDocument::override($current, $key) ?? (null === $master ? null : Occurrences::override($current, $master, $key));
                if (null !== $override) {
                    $cancelled[] = $override;
                }
            }
        }
        foreach ($cancelled as $event) {
            Props::set($event, 'STATUS', 'CANCELLED');
            Props::set($event, 'SEQUENCE', $invitation->getSequence());
        }
        $this->store->save($object, $current);
    }

    /**
     * @param list<string> $self
     */
    private function reply(CalendarInvitation $invitation, VCalendar $itip, ?CalendarObject $object, array $self, string $sender): void
    {
        if (null === $object) {
            return;
        }
        $current = CalendarDocument::parse($object->getIcal());
        $master = CalendarDocument::master($current) ?? CalendarDocument::events($current)[0] ?? null;
        $organizer = null === $master ? null : Props::get($master, 'ORGANIZER');
        if (null === $organizer || !\in_array(Props::address(Props::value($organizer)), $self, true)) {
            return;
        }
        // The answer counts only from the attendee it is about, and only for someone the event already invites:
        // otherwise anyone could add themselves and receive every later update.
        $attendee = null;
        foreach (CalendarDocument::events($itip) as $event) {
            foreach (Props::all($event, 'ATTENDEE') as $candidate) {
                if (Props::address(Props::value($candidate)) !== $sender) {
                    $this->logger->info('Ignoring calendar mail', ['invitation' => $invitation->getId()->toRfc4122(), 'reason' => 'the reply speaks for someone other than its sender']);

                    return;
                }
                $attendee = $candidate;
            }
        }
        if (null === $attendee || !self::attends($current, $sender)) {
            $this->logger->info('Ignoring calendar mail', ['invitation' => $invitation->getId()->toRfc4122(), 'reason' => 'the sender is not an attendee']);

            return;
        }

        $message = new ItipMessage();
        $message->method = 'REPLY';
        $message->component = 'VEVENT';
        $message->uid = $invitation->getUid();
        $message->sequence = $invitation->getSequence();
        $message->sender = Props::value($attendee);
        $message->recipient = Props::value($organizer);
        $message->message = $itip;

        $updated = new Broker()->processMessage($message, $current);
        if ($updated instanceof VCalendar) {
            $this->store->save($object, $updated);
        }
    }

    private function targetCalendar(CalendarInvitation $invitation, User $user): ?Calendar
    {
        $default = $this->calendars->findDefaultFor($invitation->getMessage()->getMailAccount());
        if (null !== $default) {
            // The account's provider puts invitations into its calendar itself; Tresso would only duplicate them.
            return $default->providerSchedules() || !$default->isWritable() ? null : $default;
        }

        return $this->localCalendars->ensure($user);
    }

    /**
     * The organizer's address, the same on every component, or null when there is none or they differ.
     */
    private static function organizer(VCalendar $calendar): ?string
    {
        $organizers = [];
        foreach (CalendarDocument::events($calendar) as $event) {
            $organizer = Props::get($event, 'ORGANIZER');
            $organizers[] = null === $organizer ? null : Props::address(Props::value($organizer));
        }
        $organizers = array_unique($organizers);

        return 1 === \count($organizers) ? $organizers[0] : null;
    }

    /**
     * Whether every component names this organizer, and the mail comes from them or from who they let send for them
     * (SENT-BY, as Outlook delegates do).
     */
    private static function sentBy(VCalendar $itip, string $organizer, string $sender): bool
    {
        $events = CalendarDocument::events($itip);
        if ([] === $events) {
            return false;
        }
        foreach ($events as $event) {
            $property = Props::get($event, 'ORGANIZER');
            if (null === $property || Props::address(Props::value($property)) !== $organizer) {
                return false;
            }
            $sentBy = Props::param($property, 'SENT-BY');
            if ($sender !== $organizer && (null === $sentBy || Props::address($sentBy) !== $sender)) {
                return false;
            }
        }

        return true;
    }

    private static function attends(VCalendar $calendar, string $address): bool
    {
        foreach (CalendarDocument::events($calendar) as $event) {
            foreach (Props::all($event, 'ATTENDEE') as $attendee) {
                if (Props::address(Props::value($attendee)) === $address) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<string> $self
     */
    private static function invites(VCalendar $itip, array $self): bool
    {
        foreach (CalendarDocument::events($itip) as $event) {
            $organizer = Props::get($event, 'ORGANIZER');
            if (null !== $organizer && \in_array(Props::address(Props::value($organizer)), $self, true)) {
                return false;
            }
            foreach (Props::all($event, 'ATTENDEE') as $attendee) {
                if (\in_array(Props::address(Props::value($attendee)), $self, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function sequence(VCalendar $calendar, string $recurrenceKey): int
    {
        $sequence = 0;
        foreach (CalendarDocument::events($calendar) as $event) {
            $key = CalendarDocument::recurrenceKey($event);
            if ('' === $recurrenceKey || $key === $recurrenceKey || '' === $key) {
                $sequence = max($sequence, Props::int($event, 'SEQUENCE'));
            }
        }

        return $sequence;
    }
}
