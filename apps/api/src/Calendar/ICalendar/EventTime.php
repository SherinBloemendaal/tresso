<?php

declare(strict_types=1);

namespace App\Calendar\ICalendar;

/**
 * The start or end of an event as the user means it: a date (all-day), a wall-clock time in an IANA time zone, or a
 * floating wall-clock time (no zone: the same clock time wherever the reader is).
 */
final readonly class EventTime
{
    private function __construct(
        /** Y-m-d for dates, Y-m-d\TH:i:s for times. */
        public string $value,
        public ?string $timeZone,
        public bool $isDate,
    ) {
    }

    public static function date(\DateTimeInterface $date): self
    {
        return new self($date->format('Y-m-d'), null, true);
    }

    public static function dateTime(\DateTimeInterface $wallClock, ?string $timeZone): self
    {
        return new self($wallClock->format('Y-m-d\TH:i:s'), $timeZone, false);
    }

    /**
     * @param array{date?: mixed, dateTime?: mixed, timeZone?: mixed} $data
     */
    public static function fromArray(array $data): self
    {
        if (\is_string($data['date'] ?? null)) {
            return self::date(self::parse($data['date'], 'Y-m-d', '!Y-m-d'));
        }
        if (\is_string($data['dateTime'] ?? null)) {
            $timeZone = \is_string($data['timeZone'] ?? null) && '' !== $data['timeZone'] ? $data['timeZone'] : null;
            if (null !== $timeZone && !\in_array($timeZone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true) && 'UTC' !== $timeZone) {
                throw new \InvalidArgumentException(\sprintf('Unknown time zone "%s".', $timeZone));
            }

            return self::dateTime(self::parse($data['dateTime'], 'Y-m-d\TH:i:s', '!Y-m-d\TH:i:s'), $timeZone);
        }

        throw new \InvalidArgumentException('An event time needs a date or a dateTime.');
    }

    /**
     * The instant this time stands for. Dates and floating times are read in $fallback (the reader's zone).
     */
    public function toInstant(\DateTimeZone $fallback): \DateTimeImmutable
    {
        $zone = null === $this->timeZone ? $fallback : new \DateTimeZone($this->timeZone);
        $format = $this->isDate ? '!Y-m-d' : '!Y-m-d\TH:i:s';
        $instant = \DateTimeImmutable::createFromFormat($format, $this->value, $zone);
        if (false === $instant) {
            throw new \UnexpectedValueException(\sprintf('Stored event time "%s" is invalid.', $this->value));
        }

        return $instant;
    }

    /**
     * @return array{date: string}|array{dateTime: string, timeZone: ?string}
     */
    public function toArray(): array
    {
        return $this->isDate ? ['date' => $this->value] : ['dateTime' => $this->value, 'timeZone' => $this->timeZone];
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value && $this->timeZone === $other->timeZone && $this->isDate === $other->isDate;
    }

    private static function parse(string $value, string $expect, string $format): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
        if (false === $parsed || $parsed->format($expect) !== $value) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid %s.', $value, 'Y-m-d' === $expect ? 'date (YYYY-MM-DD)' : 'local date and time (YYYY-MM-DDTHH:MM:SS)'));
        }

        return $parsed;
    }
}
