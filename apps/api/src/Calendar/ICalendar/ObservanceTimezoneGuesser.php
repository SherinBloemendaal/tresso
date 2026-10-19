<?php

declare(strict_types=1);

namespace App\Calendar\ICalendar;

use Sabre\VObject\Component;
use Sabre\VObject\Component\VTimeZone;
use Sabre\VObject\Recur\RRuleIterator;
use Sabre\VObject\TimezoneGuesser\TimezoneGuesser;
use Sabre\VObject\TimeZoneUtil;

/**
 * Finds the IANA zone a VTIMEZONE describes when its TZID is no known name (Outlook writes "Customized Time Zone" and
 * the like): the zone whose offsets and changes in the coming year are the ones the VTIMEZONE's observances give.
 * Without it, sabre reads such times as UTC.
 */
final class ObservanceTimezoneGuesser implements TimezoneGuesser
{
    /** @var array<string, ?\DateTimeZone> zone by observances */
    private array $found = [];

    /** Makes sabre ask this guesser after its own ones; once per process. */
    public static function register(): void
    {
        static $registered = false;
        if (!$registered) {
            TimeZoneUtil::addTimezoneGuesser('observances', new self());
            $registered = true;
        }
    }

    public function guess(VTimeZone $vtimezone, bool $failIfUncertain = false): ?\DateTimeZone
    {
        $year = (int) new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('Y');
        $onsets = self::onsets($vtimezone, $year + 2);
        if ([] === $onsets) {
            return null;
        }
        $fingerprint = hash('sha256', serialize($onsets));
        if (\array_key_exists($fingerprint, $this->found)) {
            return $this->found[$fingerprint];
        }

        // The moments to compare: the middle of each month of the coming year, and just around each change in it.
        $moments = [];
        for ($month = 1; $month <= 12; ++$month) {
            $moments[] = new \DateTimeImmutable(\sprintf('%d-%02d-15 12:00:00', $year + 1, $month), new \DateTimeZone('UTC'))->getTimestamp();
        }
        foreach ($onsets as [$at]) {
            if ((int) gmdate('Y', $at) === $year + 1) {
                $moments[] = $at - 3600;
                $moments[] = $at + 3600;
            }
        }
        $expected = array_map(static fn (int $moment): int => self::offsetAt($onsets, $moment), $moments);

        foreach (self::candidates() as $name) {
            $zone = new \DateTimeZone($name);
            foreach ($moments as $index => $moment) {
                if ($zone->getOffset(new \DateTimeImmutable('@'.$moment)) !== $expected[$index]) {
                    continue 2;
                }
            }

            return $this->found[$fingerprint] = $zone;
        }

        return $this->found[$fingerprint] = null;
    }

    /**
     * Every zone name, the regions most calendars use first, so a zone with European rules is found as one in Europe.
     *
     * @return list<string>
     */
    private static function candidates(): array
    {
        $order = ['Europe', 'America', 'Asia', 'Australia', 'Pacific', 'Africa', 'Atlantic', 'Indian'];
        $names = \DateTimeZone::listIdentifiers();
        usort($names, static function (string $a, string $b) use ($order): int {
            $rank = static fn (string $name): int => false === ($index = array_search(explode('/', $name)[0], $order, true)) ? \count($order) : (int) $index;

            return [$rank($a), $a] <=> [$rank($b), $b];
        });

        return $names;
    }

    /**
     * When each observance starts (in UTC), with the offset it brings, sorted.
     *
     * @return list<array{int, int, int}> [moment, offset to, offset from]
     */
    private static function onsets(VTimeZone $vtimezone, int $untilYear): array
    {
        $onsets = [];
        foreach ($vtimezone->children() as $observance) {
            if (!$observance instanceof Component || !\in_array($observance->name, ['STANDARD', 'DAYLIGHT'], true)) {
                continue;
            }
            $from = self::seconds(Props::text($observance, 'TZOFFSETFROM'));
            $to = self::seconds(Props::text($observance, 'TZOFFSETTO'));
            $start = \DateTimeImmutable::createFromFormat('Ymd\THis', Props::text($observance, 'DTSTART') ?? '', new \DateTimeZone('UTC'));
            if (null === $from || null === $to || false === $start) {
                continue;
            }
            $starts = [$start];
            $rule = Props::text($observance, 'RRULE');
            if (null !== $rule) {
                $starts = [];
                foreach (new RRuleIterator($rule, $start) as $occurrence) {
                    if (!$occurrence instanceof \DateTimeInterface || (int) $occurrence->format('Y') > $untilYear || \count($starts) > 500) {
                        break;
                    }
                    $starts[] = $occurrence;
                }
            }
            foreach ($starts as $local) {
                $onsets[] = [$local->getTimestamp() - $from, $to, $from];
            }
        }
        usort($onsets, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $onsets;
    }

    /**
     * @param list<array{int, int, int}> $onsets
     */
    private static function offsetAt(array $onsets, int $moment): int
    {
        $offset = $onsets[0][2];
        foreach ($onsets as [$at, $to]) {
            if ($at <= $moment) {
                $offset = $to;
            }
        }

        return $offset;
    }

    private static function seconds(?string $offset): ?int
    {
        if (null === $offset || 1 !== preg_match('/^([+-])(\d{2})(\d{2})(\d{2})?$/', $offset, $parts)) {
            return null;
        }

        return ('-' === $parts[1] ? -1 : 1) * ((int) $parts[2] * 3600 + (int) $parts[3] * 60 + (int) ($parts[4] ?? 0));
    }
}
