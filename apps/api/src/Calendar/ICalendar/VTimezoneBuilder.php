<?php

declare(strict_types=1);

namespace App\Calendar\ICalendar;

use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VTimeZone;

/**
 * Adds the VTIMEZONE that RFC 5545 requires for every TZID an object uses, built from PHP's time zone database.
 *
 * The past is written out change by change, from a year before the event. From the year after both the event and
 * today, the zone's current rule is written as yearly RRULEs (as Outlook and Apple write zones), so the zone holds for
 * every later date too: the nth (or last) weekday of a month, or the first weekday on or after a day of the month
 * (Israel's Friday before the last Sunday, Chile's first Sunday from the 2nd). A zone whose changes follow no such
 * rule gets its changes written out for a century instead, long enough for any series.
 */
final class VTimezoneBuilder
{
    private const int YEARS_BEFORE = 1;
    /** Years of changes a rule must reproduce. */
    private const int YEARS_AHEAD = 30;
    /** Years a zone without a rule gets its changes written out for. */
    private const int YEARS_WRITTEN = 100;
    private const array WEEKDAYS = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];

    /**
     * Makes sure the calendar has a VTIMEZONE for the zone that is right at $around. One that does not reach back to
     * $around, or misses changes after it, is replaced by one that covers both.
     */
    public static function ensure(VCalendar $calendar, string $zoneName, \DateTimeImmutable $around): void
    {
        if ('UTC' === $zoneName) {
            return;
        }
        $zone = new \DateTimeZone($zoneName);
        $from = $around;
        foreach (Props::components($calendar, 'VTIMEZONE') as $existing) {
            if (Props::text($existing, 'TZID') !== $zoneName) {
                continue;
            }
            if (self::covers($existing, $zone, $around)) {
                return;
            }
            $earliest = self::earliest($existing, $zone);
            if (null !== $earliest && $earliest < $from) {
                $from = $earliest;
            }
            $calendar->remove($existing);
        }

        $calendar->add(self::build($calendar, $zoneName, $zone, $from));
    }

    private static function build(VCalendar $calendar, string $zoneName, \DateTimeZone $zone, \DateTimeImmutable $around): VTimeZone
    {
        $utc = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable(\sprintf('%d-01-01 00:00:00', (int) $around->format('Y') - self::YEARS_BEFORE), $utc);
        $ruleYear = max((int) $around->format('Y'), (int) new \DateTimeImmutable('now', $utc)->format('Y')) + 1;
        $ruleStart = new \DateTimeImmutable(\sprintf('%d-01-01 00:00:00', $ruleYear), $utc);
        $end = new \DateTimeImmutable(\sprintf('%d-12-31 23:59:59', $ruleYear + self::YEARS_AHEAD), $utc);

        $lastYear = $ruleYear + self::YEARS_AHEAD;
        $transitions = self::transitions($zone, $start->getTimestamp(), $end->getTimestamp());
        $changes = self::changes($transitions);
        if (null === self::rules(array_values(array_filter($changes, static fn (array $change): bool => $change['ts'] >= $ruleStart->getTimestamp())), $lastYear)) {
            $far = new \DateTimeImmutable(\sprintf('%d-12-31 23:59:59', $ruleYear + self::YEARS_WRITTEN), $utc);
            $transitions = self::transitions($zone, $start->getTimestamp(), $far->getTimestamp());
        }

        /** @var VTimeZone $vtimezone */
        $vtimezone = $calendar->createComponent('VTIMEZONE');
        $vtimezone->add('TZID', $zoneName);
        $vtimezone->add('X-LIC-LOCATION', $zoneName);

        // The first entry is the state at the start; the others are the changes after it.
        $initial = $transitions[0];
        $vtimezone->add(self::observance($calendar, $initial['isdst'], $start->getTimestamp(), $initial['offset'], $initial['offset'], $initial['abbr']));

        $past = [];
        $future = [];
        foreach (self::changes($transitions) as $change) {
            if ($change['ts'] < $ruleStart->getTimestamp()) {
                $past[] = $change;
            } else {
                $future[] = $change;
            }
        }

        $rules = self::rules(array_values(array_filter($future, static fn (array $change): bool => $change['ts'] <= $end->getTimestamp())), $lastYear);
        foreach (null === $rules ? [...$past, ...$future] : $past as $change) {
            $vtimezone->add(self::observance($calendar, $change['isdst'], $change['ts'], $change['from'], $change['to'], $change['abbr']));
        }
        foreach ($rules ?? [] as $rule) {
            $change = $rule['change'];
            $observance = self::observance($calendar, $change['isdst'], $change['ts'], $change['from'], $change['to'], $change['abbr']);
            $observance->add('RRULE', $rule['rrule']);
            $vtimezone->add($observance);
        }

        return $vtimezone;
    }

    /**
     * The zone's state at $from and every change of offset until $to, found from getOffset() rather than
     * getTransitions(): with the system's tz database PHP's transition list can miss changes and end in a bogus entry
     * at 2038 (the 32-bit limit), while offsets are read right. Days are sampled at noon and a change is pinned down
     * to the second; no zone changes its offset twice within one day.
     *
     * @return non-empty-list<array{ts: int, offset: int, isdst: bool, abbr: string}> the state at $from first
     */
    private static function transitions(\DateTimeZone $zone, int $from, int $to): array
    {
        $state = static function (int $timestamp) use ($zone): array {
            $moment = new \DateTimeImmutable('@'.$timestamp)->setTimezone($zone);

            return ['ts' => $timestamp, 'offset' => $moment->getOffset(), 'isdst' => '1' === $moment->format('I'), 'abbr' => $moment->format('T')];
        };
        $transitions = [$state($from)];
        $previous = $from;
        $previousOffset = $transitions[0]['offset'];
        for ($sample = $from + 43200; $previous < $to; $sample += 86400) {
            $sample = min($sample, $to);
            $offset = $state($sample)['offset'];
            if ($offset !== $previousOffset) {
                // The first second with the new offset lies in (previous, sample].
                [$low, $high] = [$previous, $sample];
                while ($high - $low > 1) {
                    $middle = intdiv($low + $high, 2);
                    if ($state($middle)['offset'] === $previousOffset) {
                        $low = $middle;
                    } else {
                        $high = $middle;
                    }
                }
                $transitions[] = $state($high);
                $previousOffset = $offset;
            }
            $previous = $sample;
        }

        return $transitions;
    }

    /**
     * The changes after the first entry of transitions(), which is the state at the start.
     *
     * @param list<array{ts: int, offset: int, isdst: bool, abbr: string}> $transitions
     *
     * @return list<array{ts: int, from: int, to: int, isdst: bool, abbr: string}>
     */
    private static function changes(array $transitions): array
    {
        $changes = [];
        for ($i = 1, $n = \count($transitions); $i < $n; ++$i) {
            $changes[] = ['ts' => $transitions[$i]['ts'], 'from' => $transitions[$i - 1]['offset'], 'to' => $transitions[$i]['offset'], 'isdst' => $transitions[$i]['isdst'], 'abbr' => $transitions[$i]['abbr']];
        }

        return $changes;
    }

    /**
     * The yearly rules behind the coming changes: one into summer time and one back, each reproducing every change of
     * its kind. Null when the changes follow no such rule; an empty list when there are none.
     *
     * A rule must also account for every year until $lastYear, so a zone whose changes stop (or whose data does)
     * gets its changes written out instead.
     *
     * @param list<array{ts: int, from: int, to: int, isdst: bool, abbr: string}> $changes
     *
     * @return list<array{change: array{ts: int, from: int, to: int, isdst: bool, abbr: string}, rrule: string}>|null
     */
    private static function rules(array $changes, int $lastYear): ?array
    {
        if ([] === $changes) {
            return [];
        }
        $byKind = [];
        foreach ($changes as $change) {
            $byKind[$change['isdst'] ? 'daylight' : 'standard'][] = $change;
        }
        if (2 !== \count($byKind)) {
            return null;
        }

        $rules = [];
        foreach ($byKind as $kind) {
            $firstYear = (int) self::local($kind[0]['ts'], $kind[0]['from'])->format('Y');
            // One change of the kind in every year from the first through $lastYear.
            if (\count($kind) < $lastYear - $firstYear + 1) {
                return null;
            }
            $rule = self::nthWeekdayRule($kind) ?? self::onOrAfterRule($kind);
            if (null === $rule) {
                return null;
            }
            $rules[] = ['change' => $kind[0], 'rrule' => $rule];
        }

        return $rules;
    }

    /**
     * The nth (or last) weekday of a month, when it gives every change of the kind.
     *
     * @param non-empty-list<array{ts: int, from: int, to: int, isdst: bool, abbr: string}> $kind
     */
    private static function nthWeekdayRule(array $kind): ?string
    {
        $local = self::local($kind[0]['ts'], $kind[0]['from']);
        $day = (int) $local->format('j');
        $nth = $day + 7 > (int) $local->format('t') ? -1 : intdiv($day - 1, 7) + 1;
        $weekday = (int) $local->format('w');
        $month = (int) $local->format('n');
        $matches = self::reproduces($kind, static fn (int $year): \DateTimeImmutable => self::nthWeekday($year, $month, $weekday, $nth));

        return $matches ? \sprintf('FREQ=YEARLY;BYMONTH=%d;BYDAY=%d%s', $month, $nth, self::WEEKDAYS[$weekday]) : null;
    }

    /**
     * The first weekday on or after a day of the month (BYDAY with seven BYMONTHDAYs), when one such day gives every
     * change of the kind.
     *
     * @param non-empty-list<array{ts: int, from: int, to: int, isdst: bool, abbr: string}> $kind
     */
    private static function onOrAfterRule(array $kind): ?string
    {
        $local = self::local($kind[0]['ts'], $kind[0]['from']);
        $day = (int) $local->format('j');
        $weekday = (int) $local->format('w');
        $month = (int) $local->format('n');
        // February's last days vary, so a rule there stays within the 28th.
        $lastDay = 2 === $month ? 28 : (int) $local->format('t');
        for ($from = max(1, $day - 6); $from <= $day && $from + 6 <= $lastDay; ++$from) {
            $matches = self::reproduces($kind, static fn (int $year): \DateTimeImmutable => self::weekdayOnOrAfter($year, $month, $weekday, $from));
            if ($matches) {
                return \sprintf('FREQ=YEARLY;BYMONTH=%d;BYDAY=%s;BYMONTHDAY=%s', $month, self::WEEKDAYS[$weekday], implode(',', range($from, $from + 6)));
            }
        }

        return null;
    }

    /**
     * Whether the date a rule gives for each year is the local date of each change, at the same time and offsets.
     *
     * @param non-empty-list<array{ts: int, from: int, to: int, isdst: bool, abbr: string}> $kind
     * @param \Closure(int): \DateTimeImmutable                                              $dateOf
     */
    private static function reproduces(array $kind, \Closure $dateOf): bool
    {
        $first = $kind[0];
        $local = self::local($first['ts'], $first['from']);
        $year = (int) $local->format('Y');
        foreach ($kind as $index => $change) {
            $expected = $dateOf($year + $index)->format('Y-m-d').' '.$local->format('H:i:s');
            if (self::local($change['ts'], $change['from'])->format('Y-m-d H:i:s') !== $expected || $change['from'] !== $first['from'] || $change['to'] !== $first['to']) {
                return false;
            }
        }

        return true;
    }

    private static function weekdayOnOrAfter(int $year, int $month, int $weekday, int $day): \DateTimeImmutable
    {
        $from = new \DateTimeImmutable(\sprintf('%d-%02d-%02d', $year, $month, $day), new \DateTimeZone('UTC'));

        return $from->modify(\sprintf('+%d days', ($weekday - (int) $from->format('w') + 7) % 7));
    }

    /** The nth (or with -1 the last) given weekday of the month. */
    private static function nthWeekday(int $year, int $month, int $weekday, int $nth): \DateTimeImmutable
    {
        $first = new \DateTimeImmutable(\sprintf('%d-%02d-01', $year, $month), new \DateTimeZone('UTC'));
        if ($nth > 0) {
            return $first->modify(\sprintf('+%d days', ($weekday - (int) $first->format('w') + 7) % 7 + 7 * ($nth - 1)));
        }
        $last = $first->modify('last day of this month');

        return $last->modify(\sprintf('-%d days', ((int) $last->format('w') - $weekday + 7) % 7));
    }

    /**
     * Whether the VTIMEZONE gives the right offsets around $around: it starts before it, and has an open rule or
     * misses no change of the zone until a year after it.
     */
    private static function covers(Component $vtimezone, \DateTimeZone $zone, \DateTimeImmutable $around): bool
    {
        $earliest = self::earliest($vtimezone, $zone);
        if (null === $earliest || $earliest > $around) {
            return false;
        }
        $latest = $earliest;
        foreach (self::observances($vtimezone) as $observance) {
            $rule = strtoupper(Props::text($observance, 'RRULE') ?? '');
            if ('' !== $rule && !str_contains($rule, 'UNTIL=') && !str_contains($rule, 'COUNT=')) {
                return true;
            }
            $start = self::observanceStart($observance, $zone);
            if (null !== $start && $start > $latest) {
                $latest = $start;
            }
        }
        $horizon = $around->modify('+1 year');

        // transitions() starts with the state at the first timestamp; more entries are changes.
        return $latest >= $horizon || \count(self::transitions($zone, $latest->getTimestamp() + 1, $horizon->getTimestamp())) <= 1;
    }

    private static function earliest(Component $vtimezone, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        $earliest = null;
        foreach (self::observances($vtimezone) as $observance) {
            $start = self::observanceStart($observance, $zone);
            if (null !== $start && (null === $earliest || $start < $earliest)) {
                $earliest = $start;
            }
        }

        return $earliest;
    }

    /**
     * @return list<Component>
     */
    private static function observances(Component $vtimezone): array
    {
        return [...Props::components($vtimezone, 'STANDARD'), ...Props::components($vtimezone, 'DAYLIGHT')];
    }

    /** The observance's DTSTART, a local wall-clock time, read in the zone. */
    private static function observanceStart(Component $observance, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        $value = Props::text($observance, 'DTSTART');
        $start = null === $value ? false : \DateTimeImmutable::createFromFormat('Ymd\THis', $value, $zone);

        return false === $start ? null : $start;
    }

    private static function local(int $timestamp, int $offset): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.($timestamp + $offset));
    }

    private static function observance(VCalendar $calendar, bool $daylight, int $timestamp, int $offsetFrom, int $offsetTo, string $name): Component
    {
        // DTSTART of an observance is the local time before the change.
        $component = $calendar->createComponent($daylight ? 'DAYLIGHT' : 'STANDARD');
        $component->add('DTSTART', self::local($timestamp, $offsetFrom)->format('Ymd\THis'));
        $component->add('TZOFFSETFROM', self::offset($offsetFrom));
        $component->add('TZOFFSETTO', self::offset($offsetTo));
        $component->add('TZNAME', $name);

        return $component;
    }

    private static function offset(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);

        return \sprintf('%s%02d%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
