<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

/**
 * What a source knows of one attendee's time: when they are busy and, where it knows them, their working hours.
 */
final readonly class AttendeeAvailability
{
    /**
     * @param list<BusyTime>        $busy
     * @param list<WeeklyTime>|null $workingHours in $zone; null when unknown, so only their busy time counts
     *
     * @throws \InvalidArgumentException when working hours come without their zone
     */
    public function __construct(
        public array $busy,
        public ?array $workingHours = null,
        public ?\DateTimeZone $zone = null,
    ) {
        if (null !== $workingHours && null === $zone) {
            throw new \InvalidArgumentException('Working hours need the zone they are in.');
        }
    }

    /**
     * Their busy time in [$from, $to), with the time outside their working hours (when known) as busy too.
     *
     * @return list<BusyTime>
     */
    public function busyIn(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if (null === $this->workingHours || null === $this->zone) {
            return $this->busy;
        }
        $busy = $this->busy;
        $cursor = $from;
        foreach (WeeklyTime::intervals($this->workingHours, $from, $to, $this->zone) as [$start, $end]) {
            if ($start > $cursor) {
                $busy[] = new BusyTime($cursor, $start);
            }
            $cursor = max($cursor, $end);
        }
        if ($cursor < $to) {
            $busy[] = new BusyTime($cursor, $to);
        }

        return $busy;
    }
}
