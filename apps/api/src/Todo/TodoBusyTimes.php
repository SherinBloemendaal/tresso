<?php

declare(strict_types=1);

namespace App\Todo;

use App\Calendar\Assistant\BusyTime;
use App\Calendar\Assistant\BusyTimeSource;
use App\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * Planned todos as busy time: an open todo planned into the day is not booked over. They are in no calendar, so they
 * count whichever calendars a search looks at. A todo's id is its reference, so planning it again leaves out its own
 * time.
 */
final readonly class TodoBusyTimes implements BusyTimeSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function busyTimes(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $zone, ?array $calendarIds): iterable
    {
        $utc = new \DateTimeZone('UTC');
        /** @var list<array{id: string, title: string, planned_start: string, planned_end: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, title, planned_start, planned_end FROM todo
             WHERE owner_id = :owner AND completed_at IS NULL AND planned_start < :to AND planned_end > :from
             ORDER BY planned_start',
            [
                'owner' => $user->getId()->toRfc4122(),
                'from' => $from->setTimezone($utc)->format('Y-m-d H:i:s'),
                'to' => $to->setTimezone($utc)->format('Y-m-d H:i:s'),
            ],
        );

        $busy = [];
        foreach ($rows as $row) {
            $busy[] = new BusyTime(new \DateTimeImmutable($row['planned_start'], $utc), new \DateTimeImmutable($row['planned_end'], $utc), $row['title'], [$row['id']]);
        }

        return $busy;
    }
}
