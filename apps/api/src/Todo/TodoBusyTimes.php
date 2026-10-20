<?php

declare(strict_types=1);

namespace App\Todo;

use App\Calendar\Assistant\BusyTime;
use App\Calendar\Assistant\BusyTimeSource;
use App\Entity\User;
use App\Security\Scope;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Planned todos as busy time: an open todo planned into the day is not booked over. They are in no calendar, so they
 * count whichever calendars a search looks at. A todo's id is its reference, so planning it again leaves out its own
 * time.
 *
 * The label explains slots (`freeFrom`, `freeUntil` of the meeting time finder). Todo titles are the user's own text,
 * readable with the todos scope only: a caller without it (an app with calendar access) sees a neutral label.
 */
final readonly class TodoBusyTimes implements BusyTimeSource
{
    public const string NEUTRAL_LABEL = 'Planned todo';

    public function __construct(
        private Connection $connection,
        private AuthorizationCheckerInterface $authorization,
    ) {
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

        if ([] === $rows) {
            return [];
        }
        $titles = $this->authorization->isGranted(Scope::ROLE_TODOS_READ);
        $busy = [];
        foreach ($rows as $row) {
            $busy[] = new BusyTime(new \DateTimeImmutable($row['planned_start'], $utc), new \DateTimeImmutable($row['planned_end'], $utc), $titles ? $row['title'] : self::NEUTRAL_LABEL, [$row['id']]);
        }

        return $busy;
    }
}
