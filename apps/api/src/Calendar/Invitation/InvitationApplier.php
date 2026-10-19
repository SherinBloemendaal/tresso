<?php

declare(strict_types=1);

namespace App\Calendar\Invitation;

use App\Calendar\CalendarStore;
use App\Calendar\ICalendar\CalendarDocument;
use App\Calendar\ICalendar\InvalidCalendarData;
use App\Calendar\ICalendar\Occurrences;
use App\Calendar\ICalendar\Props;
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
 * Calendars whose provider schedules by itself (CalDAV auto-schedule, Google, Microsoft) get invitations from the
 * provider; Tresso never writes those events, so the same event is not there twice.
 */
#[AsMessageHandler]
final readonly class InvitationApplier
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CalendarObjectRepository $objects,
        private CalendarRepository $calendars,
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
            try {
                $this->apply($invitation);
            } catch (InvalidCalendarData $e) {
                $this->logger->info('Calendar mail could not be applied', ['invitation' => $command->invitationId, 'error' => $e->getMessage()]);
            }
            $invitation->markApplied(new \DateTimeImmutable());
            $this->entityManager->flush();
        });
        $this->entityManager->clear();
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

        match ($invitation->getMethod()) {
            ItipMethod::Request => $this->request($invitation, $itip, $object, $user, $self),
            ItipMethod::Cancel => $this->cancel($invitation, $itip, $object),
            ItipMethod::Reply => $this->reply($invitation, $itip, $object, $self),
            default => null,
        };
    }

    /**
     * @param list<string> $self
     */
    private function request(CalendarInvitation $invitation, VCalendar $itip, ?CalendarObject $object, User $user, array $self): void
    {
        if (!self::invites($itip, $self)) {
            return;
        }

        if (null === $object) {
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

    private function cancel(CalendarInvitation $invitation, VCalendar $itip, ?CalendarObject $object): void
    {
        if (null === $object) {
            return;
        }
        $current = CalendarDocument::parse($object->getIcal());
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
    private function reply(CalendarInvitation $invitation, VCalendar $itip, ?CalendarObject $object, array $self): void
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
        $event = CalendarDocument::events($itip)[0] ?? null;
        $attendee = null === $event ? null : Props::get($event, 'ATTENDEE');
        if (null === $attendee) {
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

        return $this->calendars->findOneBy(['owner' => $user, 'mailAccount' => null, 'isDefault' => true]) ?? $this->calendars->findLocal($user);
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
