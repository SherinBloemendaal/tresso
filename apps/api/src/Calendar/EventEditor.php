<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Calendar\ICalendar\CalendarDocument;
use App\Calendar\ICalendar\EventReader;
use App\Calendar\ICalendar\EventTime;
use App\Calendar\ICalendar\InvalidCalendarData;
use App\Calendar\ICalendar\Occurrences;
use App\Calendar\ICalendar\Props;
use App\Calendar\ICalendar\RecurrenceKey;
use App\Calendar\ICalendar\VTimezoneBuilder;
use App\Calendar\Scheduling\ItipMailer;
use App\Entity\Calendar;
use App\Entity\CalendarEvent;
use App\Entity\CalendarObject;
use App\Entity\MailAccount;
use App\Enum\EditScope;
use App\Enum\ParticipationStatus;
use App\Repository\CalendarObjectRepository;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\ITip\Broker;
use Sabre\VObject\ITip\Message as ItipMessage;
use Sabre\VObject\Property\ICalendar\Recur;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Settings;
use Symfony\Component\Uid\Uuid;

/**
 * The user's changes to events: create, edit and delete (for recurring events: this occurrence, this and following,
 * or all), and responses to invitations. Every change edits the resource's iCalendar text, so whatever Tresso does not
 * model survives; then the resource is saved (rows, provider write) and attendees or the organizer are told when the
 * calendar's provider does not do that itself. Runs inside the caller's transaction.
 */
final readonly class EventEditor
{
    /** Properties whose change makes an update attendees must act on (RFC 5546 2.1.4). */
    private const array SIGNIFICANT = ['DTSTART', 'DTEND', 'RRULE', 'RDATE', 'EXDATE', 'LOCATION', 'STATUS'];
    private const int MAX_RECURRENCES = 100000;

    public function __construct(
        private CalendarStore $store,
        private CalendarObjectRepository $objects,
        private SelfAddresses $selfAddresses,
        private ItipMailer $mailer,
    ) {
        Settings::$maxRecurrences = self::MAX_RECURRENCES;
    }

    public function create(Calendar $calendar, EventChanges $changes, ?MailAccount $organizer, bool $notify = true): CalendarEvent
    {
        self::requireWritable($calendar);
        $uid = Uuid::v7()->toRfc4122().'@tresso';
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $self = $this->selfAddresses->of($calendar->getOwner());

        $vcalendar = CalendarDocument::create();
        /** @var VEvent $event */
        $event = $vcalendar->add('VEVENT', ['UID' => $uid, 'DTSTAMP' => $now, 'CREATED' => $now, 'SEQUENCE' => 0]);
        if ([] !== $changes->attendees) {
            $organizer ??= $calendar->getMailAccount() ?? throw new EditRejected('Choose the mail account that sends the invitations.');
            $event->add('ORGANIZER', 'mailto:'.$organizer->getEmailAddress(), ['CN' => $organizer->getDisplayName()]);
        }
        $this->write($event, $changes, $vcalendar, true, $self);
        // A new event is its own first version.
        Props::set($event, 'SEQUENCE', 0);

        $rows = $this->store->save(new CalendarObject($calendar, $uid, ''), $vcalendar);
        $this->schedule($calendar, null, $vcalendar, $notify);

        return $rows[''];
    }

    /**
     * @param string|null $recurrenceKey the occurrence the user edited, for recurring events
     */
    public function update(CalendarEvent $row, EventChanges $changes, EditScope $scope, ?string $recurrenceKey, ?Calendar $moveTo = null, bool $notify = true): CalendarEvent
    {
        $calendar = $row->getCalendar();
        self::requireWritable($calendar);
        $object = $this->objects->lock($row->getObject());
        $old = CalendarDocument::parse($object->getIcal());
        $vcalendar = CalendarDocument::parse($object->getIcal());
        [$master, $key] = $this->target($vcalendar, $row, $recurrenceKey);
        $self = $this->selfAddresses->of($calendar->getOwner());

        if (null !== $moveTo && !$moveTo->getId()->equals($calendar->getId())) {
            if (null !== $key && EditScope::All !== $scope) {
                throw new EditRejected('Only the whole series can move to another calendar.');
            }
            self::requireWritable($moveTo);

            return $this->move($object, $vcalendar, $old, $master, $changes, $key, $moveTo, $notify, $self);
        }

        $editedKey = '';
        if (null === $key && null !== $master && self::isSeries($master)) {
            $this->editAll($vcalendar, $master, $changes, null, $self);
        } elseif (null === $key) {
            $this->write($master ?? throw new EditRejected('This event no longer exists.'), $changes, $vcalendar, true, $self);
        } elseif (EditScope::This === $scope || null === $master) {
            $occurrence = $this->occurrence($vcalendar, $master, $key);
            $this->write($occurrence, $changes, $vcalendar, false, $self);
            $editedKey = $key;
        } elseif (EditScope::All === $scope || $this->isFirst($master, $key)) {
            $this->editAll($vcalendar, $master, $changes, $key, $self);
        } else {
            $newObject = $this->split($object, $vcalendar, $master, $key, $changes, $self, $notify);
            $this->store->save($object, $vcalendar);
            $this->schedule($calendar, $old, $vcalendar, $notify);

            return $newObject;
        }

        $rows = $this->store->save($object, $vcalendar);
        $this->schedule($calendar, $old, $vcalendar, $notify);

        return $rows[$editedKey] ?? $rows[''] ?? array_values($rows)[0];
    }

    public function delete(CalendarEvent $row, EditScope $scope, ?string $recurrenceKey, bool $notify = true): void
    {
        $calendar = $row->getCalendar();
        self::requireWritable($calendar);
        $object = $this->objects->lock($row->getObject());
        $old = CalendarDocument::parse($object->getIcal());
        $vcalendar = CalendarDocument::parse($object->getIcal());
        [$master, $key] = $this->target($vcalendar, $row, $recurrenceKey);

        if (null === $key || EditScope::All === $scope || (null !== $master && EditScope::Following === $scope && $this->isFirst($master, $key))) {
            $this->store->remove($object);
            $this->schedule($calendar, $old, null, $notify);

            return;
        }

        if (null === $master) {
            $override = CalendarDocument::override($vcalendar, $key);
            if (null !== $override) {
                $vcalendar->remove($override);
            }
            if ([] === CalendarDocument::events($vcalendar)) {
                $this->store->remove($object);
                $this->schedule($calendar, $old, null, $notify);

                return;
            }
        } elseif (EditScope::This === $scope) {
            $override = CalendarDocument::override($vcalendar, $key);
            if (null !== $override) {
                $vcalendar->remove($override);
            }
            self::addExdate($master, $key);
            self::touch($master, true);
        } else {
            $this->endBefore($vcalendar, $master, $key);
            self::touch($master, true);
        }

        $this->store->save($object, $vcalendar);
        $this->schedule($calendar, $old, $vcalendar, $notify);
    }

    /**
     * The owner's answer to an invitation, for the whole series or one occurrence.
     */
    public function respond(CalendarEvent $row, ParticipationStatus $response, ?string $recurrenceKey, ?string $comment, bool $notify = true): CalendarEvent
    {
        $calendar = $row->getCalendar();
        $object = $this->objects->lock($row->getObject());
        $old = CalendarDocument::parse($object->getIcal());
        $vcalendar = CalendarDocument::parse($object->getIcal());
        [$master, $key] = $this->target($vcalendar, $row, $recurrenceKey);
        $self = $this->selfAddresses->of($calendar->getOwner());

        if (null === $key) {
            $components = CalendarDocument::events($vcalendar);
        } else {
            $components = [$this->occurrence($vcalendar, $master, $key)];
        }

        $answered = false;
        foreach ($components as $component) {
            $organizer = Props::get($component, 'ORGANIZER');
            if (null !== $organizer && \in_array(Props::address(Props::value($organizer)), $self, true)) {
                throw new EditRejected('You organize this event, so there is no invitation to answer.');
            }
            foreach (Props::all($component, 'ATTENDEE') as $attendee) {
                if (\in_array(Props::address(Props::value($attendee)), $self, true)) {
                    $attendee['PARTSTAT'] = $response->toICalendar();
                    $attendee->offsetUnset('RSVP');
                    $answered = true;
                }
            }
            self::touch($component, false);
        }
        if (!$answered) {
            throw new EditRejected('You are not among the attendees of this event.');
        }

        $rows = $this->store->save($object, $vcalendar);
        if ($notify && !$calendar->providerSchedules()) {
            $messages = self::itip(new Broker()->parseEvent(clone $vcalendar, self::hrefs($self), clone $old));
            foreach ($messages as $message) {
                if (null !== $comment && '' !== trim($comment)) {
                    foreach (CalendarDocument::events($message->message) as $event) {
                        Props::set($event, 'COMMENT', trim($comment));
                    }
                }
            }
            $this->mailer->send($calendar->getOwner(), $messages, $row->getMessage());
        }

        return $rows[$key ?? ''] ?? $rows[''] ?? array_values($rows)[0];
    }

    /**
     * The master (when the resource is a series or single event) and the occurrence the change is about (null for the
     * master or a single event).
     *
     * @return array{?VEvent, ?string}
     */
    private function target(VCalendar $vcalendar, CalendarEvent $row, ?string $recurrenceKey): array
    {
        $master = CalendarDocument::master($vcalendar);
        if ($row->isOverride()) {
            return [$master, $row->getRecurrenceKey()];
        }
        if (null === $master) {
            throw new EditRejected('This event no longer exists.');
        }
        if (!self::isSeries($master) || null === $recurrenceKey || '' === $recurrenceKey) {
            return [$master, null];
        }
        if (!RecurrenceKey::isValid($recurrenceKey)) {
            throw new EditRejected('recurrenceId is not a valid occurrence (YYYYMMDD or YYYYMMDDTHHMMSSZ).');
        }

        return [$master, $recurrenceKey];
    }

    /**
     * @param list<string> $self the owner's addresses
     */
    private function write(VEvent $event, EventChanges $changes, VCalendar $vcalendar, bool $withRecurrence, array $self): void
    {
        $before = self::significantState($event);

        self::setText($event, 'SUMMARY', $changes->summary);
        self::setText($event, 'DESCRIPTION', $changes->description);
        self::setText($event, 'LOCATION', $changes->location);
        self::setText($event, 'URL', $changes->url ?? '');
        $event->remove('DURATION');
        self::setTime($event, 'DTSTART', $changes->start, $vcalendar);
        self::setTime($event, 'DTEND', $changes->end, $vcalendar);
        if ($withRecurrence) {
            $event->remove('RRULE');
            if (null !== $changes->rrule) {
                $event->add('RRULE', $changes->rrule);
            }
        }
        Props::set($event, 'STATUS', $changes->status->toICalendar());
        Props::set($event, 'TRANSP', $changes->transparency->toICalendar());
        Props::set($event, 'CLASS', $changes->visibility->toICalendar());
        $this->writeAttendees($event, $changes, $self);
        self::writeReminders($event, $changes);
        self::writeConference($event, $changes->conferenceUrl);

        self::touch($event, self::significantState($event) !== $before);
    }

    /**
     * @param list<string> $self
     */
    private function writeAttendees(VEvent $event, EventChanges $changes, array $self): void
    {
        /** @var array<string, Property> $existing */
        $existing = [];
        foreach (Props::all($event, 'ATTENDEE') as $attendee) {
            $existing[Props::address(Props::value($attendee))] = $attendee;
        }
        $event->remove('ATTENDEE');

        foreach ($changes->attendees as $attendee) {
            $previous = $existing[$attendee['address']] ?? null;
            $parameters = [];
            if (null !== $previous) {
                foreach ($previous->parameters() as $name => $parameter) {
                    if (\is_string($name) && $parameter instanceof Parameter) {
                        $parameters[$name] = (string) $parameter;
                    }
                }
            } else {
                $parameters['PARTSTAT'] = 'NEEDS-ACTION';
                $parameters['RSVP'] = 'TRUE';
                $parameters['CUTYPE'] = 'INDIVIDUAL';
            }
            unset($parameters['CN']);
            if (null !== $attendee['name'] && '' !== $attendee['name']) {
                $parameters['CN'] = $attendee['name'];
            }
            $parameters['ROLE'] = match ($attendee['role']) {
                'chair' => 'CHAIR',
                'optional' => 'OPT-PARTICIPANT',
                'non-participant' => 'NON-PARTICIPANT',
                default => 'REQ-PARTICIPANT',
            };
            $event->add('ATTENDEE', 'mailto:'.$attendee['address'], $parameters);
        }

        $organizer = Props::get($event, 'ORGANIZER');
        if ([] === $changes->attendees && null !== $organizer && \in_array(Props::address(Props::value($organizer)), $self, true)) {
            // Without attendees an event the owner organizes is no scheduling object any more.
            $event->remove($organizer);
        }
    }


    private static function writeReminders(VEvent $event, EventChanges $changes): void
    {
        foreach (Props::components($event, 'VALARM') as $alarm) {
            $trigger = Props::get($alarm, 'TRIGGER');
            $relative = null !== $trigger && !$trigger instanceof DateTimeProperty && 'END' !== strtoupper(Props::param($trigger, 'RELATED') ?? 'START');
            if ($relative) {
                $event->remove($alarm);
            }
        }
        foreach (array_values(array_unique($changes->reminders)) as $minutes) {
            $event->add('VALARM', [
                'ACTION' => 'DISPLAY',
                'DESCRIPTION' => '' === $changes->summary ? 'Reminder' : $changes->summary,
                'TRIGGER' => 0 === $minutes ? 'PT0S' : \sprintf('-PT%dM', $minutes),
            ]);
        }
    }

    private static function writeConference(VEvent $event, ?string $url): void
    {
        $existing = null;
        foreach (Props::all($event, 'CONFERENCE') as $conference) {
            $value = trim(Props::value($conference));
            if (str_starts_with(strtolower($value), 'https://')) {
                $existing = $conference;
            }
        }
        if (null !== $existing && $url === trim(Props::value($existing))) {
            return;
        }
        if (null !== $existing) {
            $event->remove($existing);
        }
        if (null !== $url) {
            $event->add('CONFERENCE', $url, ['VALUE' => 'URI', 'FEATURE' => 'VIDEO']);
        }
    }

    /**
     * "All occurrences": the series moves by the same shift the user gave the edited occurrence, and its overrides and
     * exceptions move with it. A new recurrence rule, or a switch between all-day and timed, starts the series fresh.
     */
    /**
     * @param list<string> $self
     */
    private function editAll(VCalendar $vcalendar, VEvent $master, EventChanges $changes, ?string $key, array $self): void
    {
        $masterStart = EventReader::time(self::dtStart($master), $vcalendar);
        $anchor = null === $key ? $masterStart : $this->occurrenceStart($master, $key, $vcalendar);
        $oldRule = Props::text($master, 'RRULE');

        $sameKind = $anchor->isDate === $changes->start->isDate;
        $shift = $sameKind ? self::wallClockSeconds($changes->start) - self::wallClockSeconds($anchor) : 0;
        $length = self::wallClockSeconds($changes->end) - self::wallClockSeconds($changes->start);

        if ($sameKind) {
            $newStart = self::shiftTime($masterStart, $shift, $changes->start->timeZone);
        } else {
            $newStart = $changes->start->isDate
                ? EventTime::date(self::wallClock($masterStart))
                : EventTime::dateTime(new \DateTimeImmutable(self::wallClock($masterStart)->format('Y-m-d').' '.self::wallClock($changes->start)->format('H:i:s'), new \DateTimeZone('UTC')), $changes->start->timeZone);
        }
        $newEnd = self::shiftTime($newStart, $length, $newStart->timeZone);

        $fresh = !$sameKind || self::normalizeRule($changes->rrule) !== self::normalizeRule($oldRule);
        $overrides = array_filter(CalendarDocument::events($vcalendar), static fn (VEvent $event): bool => '' !== CalendarDocument::recurrenceKey($event));
        if ($fresh) {
            foreach ($overrides as $override) {
                $vcalendar->remove($override);
            }
            $master->remove('EXDATE');
            $master->remove('RDATE');
        } elseif (0 !== $shift || $changes->start->timeZone !== $masterStart->timeZone) {
            foreach ($overrides as $override) {
                self::shiftProperty(Props::dateTime($override, 'RECURRENCE-ID'), $shift, $changes->start->timeZone, $vcalendar);
            }
            foreach ([...Props::dateTimes($master, 'EXDATE'), ...Props::dateTimes($master, 'RDATE')] as $property) {
                self::shiftProperty($property, $shift, $changes->start->timeZone, $vcalendar);
            }
        }

        $this->write($master, new EventChanges(
            $changes->summary, $changes->description, $changes->location, $changes->url, $newStart, $newEnd, $changes->rrule,
            $changes->status, $changes->transparency, $changes->visibility, $changes->attendees, $changes->reminders, $changes->conferenceUrl,
        ), $vcalendar, true, $self);
    }

    /**
     * "This and following": the series ends before the occurrence, and a new series with a new UID starts there with
     * the user's changes, taking the later overrides and exceptions along.
     */
    /**
     * @param list<string> $self
     */
    private function split(CalendarObject $object, VCalendar $vcalendar, VEvent $master, string $key, EventChanges $changes, array $self, bool $notify): CalendarEvent
    {
        $occurrence = $this->occurrenceStart($master, $key, $vcalendar);
        $oldRule = Props::text($master, 'RRULE');
        $remaining = $this->remainingCount($master, $key);

        $uid = Uuid::v7()->toRfc4122().'@tresso';
        $next = CalendarDocument::create();
        $newMaster = clone $master;
        Props::set($newMaster, 'UID', $uid);
        $newMaster->remove('EXDATE');
        $newMaster->remove('RDATE');
        $newMaster->remove('RECURRENCE-ID');
        $next->add($newMaster);
        foreach (Props::components($vcalendar, 'VTIMEZONE') as $zone) {
            $next->add(clone $zone);
        }

        $sameKind = $occurrence->isDate === $changes->start->isDate;
        $shift = $sameKind ? self::wallClockSeconds($changes->start) - self::wallClockSeconds($occurrence) : 0;

        foreach (CalendarDocument::events($vcalendar) as $override) {
            $overrideKey = CalendarDocument::recurrenceKey($override);
            if ('' === $overrideKey || $overrideKey < $key) {
                continue;
            }
            $vcalendar->remove($override);
            if (!$sameKind) {
                continue;
            }
            $moved = clone $override;
            Props::set($moved, 'UID', $uid);
            self::shiftProperty(Props::dateTime($moved, 'RECURRENCE-ID'), $shift, $changes->start->timeZone, $next);
            $next->add($moved);
        }
        foreach (['EXDATE', 'RDATE'] as $name) {
            foreach (Props::dateTimes($master, $name) as $property) {
                $later = array_values(array_filter($property->getDateTimes(new \DateTimeZone('UTC')), static fn (\DateTimeInterface $value): bool => ($property->hasTime() ? RecurrenceKey::fromInstant($value) : RecurrenceKey::fromDate($value)) >= $key));
                if ([] !== $later && $sameKind) {
                    $copy = $newMaster->add($name, $later, $property->hasTime() ? [] : ['VALUE' => 'DATE']);
                    if ($copy instanceof DateTimeProperty) {
                        self::shiftProperty($copy, $shift, $changes->start->timeZone, $next);
                    }
                }
            }
        }

        $this->endBefore($vcalendar, $master, $key);
        self::touch($master, true);

        $rule = $changes->rrule;
        if (null !== $remaining && self::normalizeRule($rule) === self::normalizeRule($oldRule)) {
            $rule = self::withCount((string) $oldRule, $remaining);
        }
        Props::set($newMaster, 'SEQUENCE', 0);
        $this->write($newMaster, new EventChanges(
            $changes->summary, $changes->description, $changes->location, $changes->url, $changes->start, $changes->end, $rule,
            $changes->status, $changes->transparency, $changes->visibility, $changes->attendees, $changes->reminders, $changes->conferenceUrl,
        ), $next, true, $self);

        $rows = $this->store->save(new CalendarObject($object->getCalendar(), $uid, ''), $next);
        $this->schedule($object->getCalendar(), null, $next, $notify);

        return $rows[''];
    }

    /**
     * @param list<string> $self
     */
    private function move(CalendarObject $object, VCalendar $vcalendar, VCalendar $old, ?VEvent $master, EventChanges $changes, ?string $key, Calendar $target, bool $notify, array $self): CalendarEvent
    {
        if (null !== $master) {
            if (null === $key) {
                $this->write($master, $changes, $vcalendar, true, $self);
            } else {
                $this->editAll($vcalendar, $master, $changes, $key, $self);
            }
        }
        if (null !== $this->objects->findOneByUid($target, $object->getUid())) {
            throw new EditRejected('The other calendar already has this event.');
        }
        $source = $object->getCalendar();
        $this->store->remove($object);
        $rows = $this->store->save(new CalendarObject($target, $object->getUid(), ''), $vcalendar);
        $this->schedule($source, $old, null, $notify && $source->getMailAccount() !== $target->getMailAccount());
        $this->schedule($target, $source->getMailAccount() === $target->getMailAccount() ? $old : null, $vcalendar, $notify);

        return $rows[''];
    }

    private function schedule(Calendar $calendar, ?VCalendar $old, ?VCalendar $new, bool $notify): void
    {
        if (!$notify || $calendar->providerSchedules()) {
            return;
        }
        $self = $this->selfAddresses->of($calendar->getOwner());
        // A null new object is how sabre's broker learns about a deletion; its docblock leaves that case out.
        /** @phpstan-ignore argument.type */
        $messages = self::itip(new Broker()->parseEvent(null === $new ? null : clone $new, self::hrefs($self), null === $old ? null : clone $old));
        // Only the organizer's side: attendees reply through respond(), never as a side effect of editing.
        $organizerMessages = array_values(array_filter($messages, static fn (ItipMessage $message): bool => \in_array($message->method, ['REQUEST', 'CANCEL'], true)));
        $this->mailer->send($calendar->getOwner(), $organizerMessages);
    }


    private function occurrence(VCalendar $vcalendar, ?VEvent $master, string $key): VEvent
    {
        $existing = CalendarDocument::override($vcalendar, $key);
        if (null !== $existing) {
            return $existing;
        }
        if (null === $master) {
            throw new EditRejected('This occurrence no longer exists.');
        }
        try {
            return Occurrences::override($vcalendar, $master, $key);
        } catch (InvalidCalendarData $e) {
            throw new EditRejected($e->getMessage(), previous: $e);
        }
    }

    private function occurrenceStart(VEvent $master, string $key, VCalendar $vcalendar): EventTime
    {
        $masterStart = EventReader::time(self::dtStart($master), $vcalendar);
        $instant = RecurrenceKey::require($key);
        if ($masterStart->isDate) {
            return EventTime::date($instant);
        }
        if (null === $masterStart->timeZone) {
            return EventTime::dateTime($instant, null);
        }

        return EventTime::dateTime($instant->setTimezone(new \DateTimeZone($masterStart->timeZone)), $masterStart->timeZone);
    }

    private function isFirst(VEvent $master, string $key): bool
    {
        return RecurrenceKey::fromProperty(self::dtStart($master)) >= $key;
    }

    /** Ends the series just before the occurrence and drops whatever belonged to the later part. */
    private function endBefore(VCalendar $vcalendar, VEvent $master, string $key): void
    {
        $start = self::dtStart($master);
        $instant = RecurrenceKey::require($key);
        $until = $start->hasTime() ? $instant->modify('-1 second')->format('Ymd\THis\Z') : $instant->modify('-1 day')->format('Ymd');

        $rule = Props::get($master, 'RRULE');
        if ($rule instanceof Recur) {
            $parts = $rule->getParts();
            unset($parts['COUNT']);
            $parts['UNTIL'] = $until;
            $rule->setParts($parts);
        }
        foreach (['EXDATE', 'RDATE'] as $name) {
            foreach (Props::dateTimes($master, $name) as $property) {
                $kept = array_values(array_filter($property->getDateTimes(), static fn (\DateTimeInterface $value): bool => ($property->hasTime() ? RecurrenceKey::fromInstant($value) : RecurrenceKey::fromDate($value)) < $key));
                if ([] === $kept) {
                    $master->remove($property);
                } else {
                    $property->setDateTimes($kept, $property->isFloating());
                }
            }
        }
        foreach (CalendarDocument::events($vcalendar) as $override) {
            $overrideKey = CalendarDocument::recurrenceKey($override);
            if ('' !== $overrideKey && $overrideKey >= $key) {
                $vcalendar->remove($override);
            }
        }
    }

    /** For a series with COUNT: how many occurrences are left from the occurrence on. */
    private function remainingCount(VEvent $master, string $key): ?int
    {
        $rule = Props::get($master, 'RRULE');
        $count = $rule instanceof Recur ? ($rule->getParts()['COUNT'] ?? null) : null;
        if (!is_numeric($count)) {
            return null;
        }
        $count = (int) $count;
        $before = 0;
        $iterator = new EventIterator([$master], null, new \DateTimeZone('UTC'));
        $isDate = !self::dtStart($master)->hasTime();
        while ($iterator->valid()) {
            $start = $iterator->getDtStart();
            if (($isDate ? RecurrenceKey::fromDate($start) : RecurrenceKey::fromInstant($start)) >= $key) {
                break;
            }
            ++$before;
            $iterator->next();
        }

        return max(1, $count - $before);
    }

    private static function withCount(string $rule, int $count): string
    {
        $parts = [];
        foreach (explode(';', $rule) as $part) {
            if ('' !== $part && !str_starts_with(strtoupper($part), 'COUNT=') && !str_starts_with(strtoupper($part), 'UNTIL=')) {
                $parts[] = $part;
            }
        }
        $parts[] = 'COUNT='.$count;

        return implode(';', $parts);
    }

    private static function addExdate(VEvent $master, string $key): void
    {
        $start = self::dtStart($master);
        $instant = RecurrenceKey::require($key);
        if (!$start->hasTime()) {
            $master->add('EXDATE', $instant, ['VALUE' => 'DATE']);

            return;
        }
        if ($start->isFloating()) {
            $exdate = $master->add('EXDATE', $instant);
            if ($exdate instanceof DateTimeProperty) {
                $exdate->setDateTime($instant, true);
            }

            return;
        }
        $master->add('EXDATE', $instant->setTimezone($start->getDateTime()->getTimezone()));
    }

    private static function dtStart(VEvent $event): DateTimeProperty
    {
        return Props::dateTime($event, 'DTSTART') ?? throw new EditRejected('The event has no start.');
    }

    private static function setTime(VEvent $event, string $name, EventTime $time, VCalendar $vcalendar): void
    {
        $event->remove($name);
        $utc = new \DateTimeZone('UTC');
        if ($time->isDate) {
            $event->add($name, $time->toInstant($utc), ['VALUE' => 'DATE']);

            return;
        }
        $instant = $time->toInstant($utc);
        if (null === $time->timeZone) {
            $property = $event->add($name, $instant);
            if ($property instanceof DateTimeProperty) {
                $property->setDateTime($instant, true);
            }

            return;
        }
        $event->add($name, $instant);
        VTimezoneBuilder::ensure($vcalendar, $time->timeZone, $instant);
    }

    /** Moves a date or date-time property by a number of wall-clock seconds, optionally into another zone. */
    private static function shiftProperty(?DateTimeProperty $property, int $seconds, ?string $zone, VCalendar $vcalendar): void
    {
        if (null === $property) {
            return;
        }
        $values = [];
        foreach ($property->getDateTimes(new \DateTimeZone('UTC')) as $value) {
            $wallClock = new \DateTimeImmutable($value->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'))->modify(\sprintf('%+d seconds', $seconds));
            $values[] = $property->hasTime() && !$property->isFloating() && null !== $zone
                ? new \DateTimeImmutable($wallClock->format('Y-m-d H:i:s'), new \DateTimeZone($zone))
                : $wallClock;
        }
        if (!$property->hasTime()) {
            $property->setDateTimes($values);
            $property['VALUE'] = 'DATE';

            return;
        }
        $property->setDateTimes($values, $property->isFloating());
        if (null !== $zone && !$property->isFloating() && 'UTC' !== $zone) {
            VTimezoneBuilder::ensure($vcalendar, $zone, $values[0]);
        }
    }

    private static function shiftTime(EventTime $time, int $seconds, ?string $zone): EventTime
    {
        $shifted = self::wallClock($time)->modify(\sprintf('%+d seconds', $seconds));

        return $time->isDate ? EventTime::date($shifted) : EventTime::dateTime($shifted, $zone);
    }

    private static function wallClock(EventTime $time): \DateTimeImmutable
    {
        return $time->toInstant(new \DateTimeZone('UTC'))->setTimezone(new \DateTimeZone($time->timeZone ?? 'UTC'));
    }

    private static function wallClockSeconds(EventTime $time): int
    {
        return new \DateTimeImmutable(self::wallClock($time)->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'))->getTimestamp();
    }

    private static function normalizeRule(?string $rule): ?string
    {
        if (null === $rule || '' === trim($rule)) {
            return null;
        }
        $parts = array_filter(explode(';', strtoupper(trim($rule))));
        sort($parts);

        return implode(';', $parts);
    }

    private static function touch(VEvent $event, bool $significant): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        Props::set($event, 'LAST-MODIFIED', $now);
        Props::set($event, 'DTSTAMP', $now);
        if ($significant) {
            Props::set($event, 'SEQUENCE', Props::int($event, 'SEQUENCE') + 1);
        }
    }

    private static function significantState(VEvent $event): string
    {
        $state = [];
        foreach (self::SIGNIFICANT as $name) {
            foreach (Props::all($event, $name) as $property) {
                $state[] = $property->serialize();
            }
        }

        return implode('|', $state);
    }

    private static function setText(VEvent $event, string $name, string $value): void
    {
        $event->remove($name);
        if ('' !== trim($value)) {
            $event->add($name, $value);
        }
    }

    /**
     * @param list<string> $addresses
     *
     * @return list<string>
     */
    private static function hrefs(array $addresses): array
    {
        return array_map(static fn (string $address): string => 'mailto:'.$address, $addresses);
    }

    private static function isSeries(VEvent $master): bool
    {
        return null !== Props::get($master, 'RRULE') || null !== Props::get($master, 'RDATE');
    }

    /**
     * @param array<mixed> $messages
     *
     * @return list<ItipMessage>
     */
    private static function itip(array $messages): array
    {
        return array_values(array_filter($messages, static fn (mixed $message): bool => $message instanceof ItipMessage));
    }

    private static function requireWritable(Calendar $calendar): void
    {
        if (!$calendar->isWritable()) {
            throw new EditRejected('This calendar is read-only.');
        }
    }
}
