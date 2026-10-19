<?php

declare(strict_types=1);

namespace App\Calendar\ICalendar;

use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;

/**
 * Identifies one occurrence of a series by its original start, the way RECURRENCE-ID does: the UTC instant
 * (Ymd\THis\Z) for timed series, the date (Ymd) for all-day series. Floating times count as UTC, which is what they
 * are stored as. Overrides, EXDATEs and RDATEs all use these keys, so they compare as plain strings.
 */
final class RecurrenceKey
{
    private const string UTC_FORMAT = 'Ymd\THis\Z';
    private const string DATE_FORMAT = 'Ymd';

    public static function fromProperty(DateTimeProperty $property): string
    {
        return $property->hasTime()
            ? self::fromInstant($property->getDateTime(new \DateTimeZone('UTC')))
            : self::fromDate($property->getDateTime(new \DateTimeZone('UTC')));
    }

    /**
     * Keys of every value of a multi-valued property (EXDATE, RDATE).
     *
     * @return list<string>
     */
    public static function allFromProperty(DateTimeProperty $property): array
    {
        $utc = new \DateTimeZone('UTC');

        return array_values(array_map(
            static fn (\DateTimeInterface $value): string => $property->hasTime() ? self::fromInstant($value) : self::fromDate($value),
            $property->getDateTimes($utc),
        ));
    }

    public static function fromInstant(\DateTimeInterface $instant): string
    {
        return \DateTimeImmutable::createFromInterface($instant)->setTimezone(new \DateTimeZone('UTC'))->format(self::UTC_FORMAT);
    }

    public static function fromDate(\DateTimeInterface $date): string
    {
        return $date->format(self::DATE_FORMAT);
    }

    public static function isDate(string $key): bool
    {
        return 1 === preg_match('/^\d{8}$/', $key);
    }

    public static function isValid(string $key): bool
    {
        return 1 === preg_match('/^\d{8}(T\d{6}Z)?$/', $key) && false !== self::toDateTime($key);
    }

    /**
     * The instant (or the date at midnight UTC) of a key.
     */
    public static function toDateTime(string $key): \DateTimeImmutable|false
    {
        return self::isDate($key)
            ? \DateTimeImmutable::createFromFormat('!'.self::DATE_FORMAT, $key, new \DateTimeZone('UTC'))
            : \DateTimeImmutable::createFromFormat('!'.self::UTC_FORMAT, $key, new \DateTimeZone('UTC'));
    }

    public static function require(string $key): \DateTimeImmutable
    {
        $dateTime = self::isValid($key) ? self::toDateTime($key) : false;
        if (false === $dateTime) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a recurrence id (YYYYMMDD or YYYYMMDDTHHMMSSZ).', $key));
        }

        return $dateTime;
    }
}
