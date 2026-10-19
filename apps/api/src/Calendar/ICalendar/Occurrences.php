<?php

declare(strict_types=1);

namespace App\Calendar\ICalendar;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Recur\MaxInstancesExceededException;
use Sabre\VObject\Recur\RRuleIterator;
use Sabre\VObject\Settings;

/**
 * The occurrences of one resource in a range, and turning one occurrence of a series into its own VEVENT (an
 * override), the way RFC 5545 models a changed occurrence.
 *
 * A series is the union of its RRULE and RDATE starts (and DTSTART), less its EXDATEs, with overrides in place of the
 * occurrences they change (RFC 5545 3.8.5). An override with RANGE=THISANDFUTURE (3.8.4.4) changes every later
 * occurrence too, shifted by as much as it moves its own. sabre's EventIterator takes either RRULE or RDATE, not both, and keeps
 * RDATEs in the order written, so the starts are put together here. Floating and all-day times are read in the
 * user's zone; recurrence ids of floating series are their wall-clock times, as RecurrenceKey reads them.
 */
final class Occurrences
{
    /**
     * Whether a series repeats more often than hourly. sabre/vobject does not advance such rules reliably, and they
     * would produce more occurrences than anyone can use, so they are refused when stored.
     */
    public static function tooDense(VEvent $event): bool
    {
        $rule = Props::text($event, 'RRULE');

        return null !== $rule && 1 === preg_match('/(^|;)FREQ=(SECONDLY|MINUTELY)(;|$)/i', $rule);
    }

    /**
     * The instances of the resource's events that overlap [$from, $to), one at a time and at most $limit, in order of
     * their start within each series. Each is keyed by the recurrence key of the component it comes from: '' for a
     * single event or the master, the override's for an override and for the occurrences a THISANDFUTURE one changes.
     *
     * @return \Generator<string, VEvent>
     */
    public static function instances(VCalendar $calendar, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $zone, int $limit): \Generator
    {
        $count = 0;
        $master = null;
        $overrides = [];
        foreach (CalendarDocument::events($calendar) as $event) {
            if (null !== Props::get($event, 'RECURRENCE-ID')) {
                $overrides[CalendarDocument::recurrenceKey($event)] = $event;
            } elseif (null !== Props::get($event, 'RRULE') || null !== Props::get($event, 'RDATE')) {
                $master = $event;
            } elseif (self::overlaps($event, $from, $to, $zone)) {
                yield '' => $event;
                if (++$count >= $limit) {
                    return;
                }
            }
        }

        if (null === $master) {
            // Occurrences without their series (invitations to single occurrences): each one on its own.
            foreach ($overrides as $key => $override) {
                if (self::overlaps($override, $from, $to, $zone)) {
                    yield (string) $key => $override;
                    if (++$count >= $limit) {
                        return;
                    }
                }
            }

            return;
        }
        if (self::tooDense($master)) {
            return;
        }

        $ranges = array_filter($overrides, static fn (VEvent $override): bool => self::changesTheFuture($override));
        ksort($ranges, \SORT_STRING);
        $length = self::length($master, $zone);
        // A THISANDFUTURE override may move its occurrences later or earlier; start looking early enough for either.
        $reach = max([0, $length, ...array_map(static fn (VEvent $range): int => abs(self::shift($range, $zone)) + self::length($range, $zone), $ranges)]);
        $seen = [];
        foreach (self::starts($master, $zone, $from->modify(\sprintf('-%d seconds', $reach))) as $key => $start) {
            if ($start >= $to->modify(\sprintf('+%d seconds', $reach))) {
                break;
            }
            $key = (string) $key;
            $seen[$key] = true;
            $source = $key;
            $instance = $overrides[$key] ?? null;
            if (null === $instance) {
                $range = self::rangeFor($ranges, $key);
                $source = null === $range ? '' : CalendarDocument::recurrenceKey($range);
                $instance = null === $range ? self::instance($master, $start, $length) : self::changed($master, $range, $start, $zone);
            }
            if (self::overlaps($instance, $from, $to, $zone)) {
                yield $source => $instance;
                if (++$count >= $limit) {
                    return;
                }
            }
        }
        // Overrides moved into the range from an occurrence outside it.
        foreach ($overrides as $key => $override) {
            if (!isset($seen[$key]) && self::overlaps($override, $from, $to, $zone) && self::isOccurrence($master, (string) $key, $zone)) {
                yield (string) $key => $override;
                if (++$count >= $limit) {
                    return;
                }
            }
        }
    }

    /**
     * The starts of a series in order, by recurrence key: DTSTART, every RRULE and RDATE start, less the EXDATEs. With
     * $from, starts before it are skipped quickly.
     *
     * @return \Generator<string, \DateTimeImmutable>
     */
    public static function starts(VEvent $master, \DateTimeZone $zone, ?\DateTimeImmutable $from = null): \Generator
    {
        $dtStart = Props::dateTime($master, 'DTSTART') ?? throw new InvalidCalendarData('The series has no start.');
        $isDate = !$dtStart->hasTime();
        $floating = true === $dtStart->isFloating();

        $excluded = [];
        foreach (Props::dateTimes($master, 'EXDATE') as $property) {
            foreach ($property->getDateTimes($zone) as $value) {
                $excluded[self::key(\DateTimeImmutable::createFromInterface($value), $isDate, $floating)] = true;
            }
        }

        $extra = [];
        foreach (Props::dateTimes($master, 'RDATE') as $property) {
            // RDATE;VALUE=PERIOD is not supported by sabre's reader; dates and date-times are, each in its own TZID.
            foreach ($property->getDateTimes($zone) as $value) {
                $extra[] = \DateTimeImmutable::createFromInterface($value);
            }
        }
        usort($extra, static fn (\DateTimeImmutable $a, \DateTimeImmutable $b): int => $a <=> $b);

        $first = \DateTimeImmutable::createFromInterface($dtStart->getDateTime($zone));
        $rule = Props::text($master, 'RRULE');
        $ruled = null === $rule ? new \ArrayIterator([$first]) : self::ruleStarts($rule, $first, $from);

        $seen = [];
        $rdates = new \ArrayIterator($extra);
        foreach (self::merge($ruled, $rdates) as $start) {
            if (null !== $from && $start < $from) {
                continue;
            }
            $key = self::key($start, $isDate, $floating);
            if (isset($seen[$key]) || isset($excluded[$key])) {
                continue;
            }
            $seen[$key] = true;
            yield $key => $start;
        }
    }

    /**
     * How many RRULE occurrences come before a recurrence key, counting those an EXDATE removes, as COUNT does
     * (RFC 5545 3.3.10: COUNT applies to the rule's set, before EXDATE). Null when the series has no RRULE.
     */
    public static function ruleOccurrencesBefore(VEvent $master, string $key, \DateTimeZone $zone): ?int
    {
        $dtStart = Props::dateTime($master, 'DTSTART');
        $rule = Props::text($master, 'RRULE');
        if (null === $dtStart || null === $rule) {
            return null;
        }
        $before = 0;
        foreach (self::ruleStarts($rule, \DateTimeImmutable::createFromInterface($dtStart->getDateTime($zone)), null) as $start) {
            if (self::key($start, !$dtStart->hasTime(), true === $dtStart->isFloating()) >= $key) {
                break;
            }
            ++$before;
        }

        return $before;
    }

    /**
     * The override for the occurrence: the existing one, or a new copy of the master at that occurrence, added to the
     * calendar.
     */
    public static function override(VCalendar $calendar, VEvent $master, string $key): VEvent
    {
        $existing = CalendarDocument::override($calendar, $key);
        if (null !== $existing) {
            return $existing;
        }

        $zone = new \DateTimeZone('UTC');
        foreach (self::starts($master, $zone, self::lookFrom($key)) as $current => $start) {
            if ($current === $key) {
                $instance = self::instance($master, $start, self::length($master, $zone));
                unset($instance->VALARM);
                foreach (Props::components($master, 'VALARM') as $alarm) {
                    $instance->add(clone $alarm);
                }
                $calendar->add($instance);

                return $instance;
            }
            // Starts come in order, so a later one means there is none at that time.
            if ($current > $key) {
                break;
            }
        }

        throw new InvalidCalendarData('The series has no occurrence at that time.');
    }

    /** Whether the series has an occurrence with this recurrence key. */
    public static function isOccurrence(VEvent $master, string $key, \DateTimeZone $zone): bool
    {
        foreach (self::starts($master, $zone, self::lookFrom($key)) as $current => $start) {
            if ($current === $key) {
                return true;
            }
            if ($current > $key) {
                return false;
            }
        }

        return false;
    }

    /**
     * Where to start looking for the occurrence with this key: a little before it, as floating and all-day keys are
     * read in a zone, so a long series is not walked from its first occurrence.
     */
    private static function lookFrom(string $key): ?\DateTimeImmutable
    {
        $moment = RecurrenceKey::toDateTime($key);

        return false === $moment ? null : $moment->modify('-2 days');
    }

    private static function changesTheFuture(VEvent $override): bool
    {
        $recurrenceId = Props::get($override, 'RECURRENCE-ID');

        return null !== $recurrenceId && 'THISANDFUTURE' === strtoupper(Props::param($recurrenceId, 'RANGE') ?? '');
    }

    /**
     * The THISANDFUTURE override that changes the occurrence with this key: the latest one before it.
     *
     * @param array<string, VEvent> $ranges sorted by key
     */
    private static function rangeFor(array $ranges, string $key): ?VEvent
    {
        $found = null;
        foreach ($ranges as $rangeKey => $range) {
            if ((string) $rangeKey >= $key) {
                break;
            }
            $found = $range;
        }

        return $found;
    }

    /** How far a THISANDFUTURE override moves its occurrence, in seconds. */
    private static function shift(VEvent $range, \DateTimeZone $zone): int
    {
        $recurrenceId = Props::dateTime($range, 'RECURRENCE-ID') ?? throw new InvalidCalendarData('The override has no recurrence id.');
        $start = Props::dateTime($range, 'DTSTART') ?? throw new InvalidCalendarData('The override has no start.');

        return $start->getDateTime($zone)->getTimestamp() - $recurrenceId->getDateTime($zone)->getTimestamp();
    }

    /**
     * A later occurrence as a THISANDFUTURE override changes it: the override's properties, moved as far from the
     * occurrence's own start as the override moved from its.
     */
    private static function changed(VEvent $master, VEvent $range, \DateTimeImmutable $start, \DateTimeZone $zone): VEvent
    {
        $recurrenceId = Props::dateTime($range, 'RECURRENCE-ID') ?? throw new InvalidCalendarData('The override has no recurrence id.');
        $rangeStart = Props::dateTime($range, 'DTSTART') ?? throw new InvalidCalendarData('The override has no start.');
        $moved = $start->add(\DateTimeImmutable::createFromInterface($recurrenceId->getDateTime($zone))->diff($rangeStart->getDateTime($zone)));
        $length = self::length($range, $zone);

        $instance = clone $range;
        $instance->remove('RECURRENCE-ID');
        $dtStart = Props::dateTime($instance, 'DTSTART') ?? throw new InvalidCalendarData('The override has no start.');
        $dtStart->setDateTime($moved, $dtStart->isFloating());
        Props::dateTime($instance, 'DTEND')?->setDateTime($moved->modify(\sprintf('+%d seconds', $length)), $dtStart->isFloating());
        $masterStart = Props::dateTime($master, 'DTSTART') ?? throw new InvalidCalendarData('The series has no start.');
        $occurrenceId = clone $masterStart;
        $occurrenceId->name = 'RECURRENCE-ID';
        $occurrenceId->setDateTime($start, $masterStart->isFloating());
        $instance->add($occurrenceId);

        return $instance;
    }

    /**
     * The recurrence key of a start: the date for all-day series, the wall-clock time for floating ones, else the
     * instant in UTC.
     */
    private static function key(\DateTimeImmutable $start, bool $isDate, bool $floating): string
    {
        return match (true) {
            $isDate => RecurrenceKey::fromDate($start),
            $floating => $start->format('Ymd\THis').'Z',
            default => RecurrenceKey::fromInstant($start),
        };
    }

    /**
     * The starts an RRULE gives (the first being DTSTART), from $from on when given.
     *
     * @return \Generator<int, \DateTimeImmutable>
     */
    private static function ruleStarts(string $rule, \DateTimeImmutable $first, ?\DateTimeImmutable $from): \Generator
    {
        $iterator = new RRuleIterator($rule, $first);
        if (null !== $from && $from > $first) {
            $iterator->fastForward($from);
        }
        $steps = 0;
        while ($iterator->valid()) {
            if (++$steps > Settings::$maxRecurrences) {
                throw new MaxInstancesExceededException('The series has more occurrences than can be looked through.');
            }
            $current = $iterator->current();
            if (!$current instanceof \DateTimeInterface) {
                return;
            }
            yield \DateTimeImmutable::createFromInterface($current);
            $iterator->next();
        }
    }

    /**
     * Two ascending streams as one.
     *
     * @param \Iterator<mixed, \DateTimeImmutable> $a
     * @param \Iterator<mixed, \DateTimeImmutable> $b
     *
     * @return \Generator<int, \DateTimeImmutable>
     */
    private static function merge(\Iterator $a, \Iterator $b): \Generator
    {
        $a->rewind();
        $b->rewind();
        while ($a->valid() || $b->valid()) {
            if (!$b->valid() || ($a->valid() && $a->current() <= $b->current())) {
                yield $a->current();
                $a->next();
            } else {
                yield $b->current();
                $b->next();
            }
        }
    }

    /** One occurrence of the master as its own event, as sabre's EventIterator::getEventObject() makes it. */
    private static function instance(VEvent $master, \DateTimeImmutable $start, int $length): VEvent
    {
        $instance = clone $master;
        foreach (['RRULE', 'RDATE', 'EXDATE', 'EXRULE', 'RECURRENCE-ID'] as $name) {
            $instance->remove($name);
        }
        $dtStart = Props::dateTime($instance, 'DTSTART') ?? throw new InvalidCalendarData('The series has no start.');
        $dtStart->setDateTime($start, $dtStart->isFloating());
        Props::dateTime($instance, 'DTEND')?->setDateTime($start->modify(\sprintf('+%d seconds', $length)), $dtStart->isFloating());
        $recurrenceId = clone $dtStart;
        $recurrenceId->name = 'RECURRENCE-ID';
        $instance->add($recurrenceId);

        return $instance;
    }

    /** The event's length in seconds: DTEND, else DURATION, else a day for dates and nothing for date-times. */
    private static function length(VEvent $event, \DateTimeZone $zone): int
    {
        $start = Props::dateTime($event, 'DTSTART') ?? throw new InvalidCalendarData('The event has no start.');
        $startValue = $start->getDateTime($zone);
        $end = Props::dateTime($event, 'DTEND');
        if (null !== $end) {
            return $end->getDateTime($zone)->getTimestamp() - $startValue->getTimestamp();
        }
        $duration = Props::get($event, 'DURATION');
        if ($duration instanceof \Sabre\VObject\Property\ICalendar\Duration) {
            return \DateTimeImmutable::createFromInterface($startValue)->add($duration->getDateInterval())->getTimestamp() - $startValue->getTimestamp();
        }

        return $start->hasTime() ? 0 : 86400;
    }

    /**
     * Whether an event overlaps [$from, $to), with floating and all-day times in the user's zone. A moment (no length)
     * overlaps when it lies in the range.
     */
    private static function overlaps(VEvent $event, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $zone): bool
    {
        $start = Props::dateTime($event, 'DTSTART');
        if (null === $start) {
            return false;
        }
        $startValue = \DateTimeImmutable::createFromInterface($start->getDateTime($zone));
        $end = $startValue->modify(\sprintf('+%d seconds', self::length($event, $zone)));

        return $startValue == $end ? $startValue >= $from && $startValue < $to : $startValue < $to && $end > $from;
    }
}
