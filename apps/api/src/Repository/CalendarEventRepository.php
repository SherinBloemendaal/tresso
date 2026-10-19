<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CalendarEvent;
use App\Entity\CalendarObject;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CalendarEvent>
 */
class CalendarEventRepository extends ServiceEntityRepository
{
    /** Dates and floating times are stored as if in UTC; every zone on earth is within this much of it. */
    public const string ZONE_MARGIN = 'PT14H';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarEvent::class);
    }

    public function findOneOwnedBy(User $owner, string $id): ?CalendarEvent
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $event = $this->createQueryBuilder('e')
            ->join('e.calendar', 'c')
            ->where('e.id = :id')
            ->andWhere('c.owner = :owner')
            ->setParameter('id', Uuid::fromString($id), 'uuid')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $event instanceof CalendarEvent ? $event : null;
    }

    /**
     * @return array<string, CalendarEvent> by recurrence key ('' for the master)
     */
    public function findForObject(CalendarObject $object): array
    {
        $byKey = [];
        foreach ($this->findBy(['object' => $object]) as $event) {
            $byKey[$event->getRecurrenceKey()] = $event;
        }

        return $byKey;
    }

    /**
     * @param list<CalendarObject> $objects
     *
     * @return array<string, array<string, CalendarEvent>> by object id, then recurrence key
     */
    public function findForObjects(array $objects): array
    {
        if ([] === $objects) {
            return [];
        }
        /** @var list<CalendarEvent> $events */
        $events = $this->createQueryBuilder('e')
            ->where('e.object IN (:objects)')
            ->setParameter('objects', array_map(static fn (CalendarObject $object): string => $object->getId()->toRfc4122(), $objects))
            ->getQuery()
            ->getResult();
        $grouped = [];
        foreach ($events as $event) {
            $grouped[$event->getObject()->getId()->toRfc4122()][$event->getRecurrenceKey()] = $event;
        }

        return $grouped;
    }

    /**
     * Objects with an event or occurrence that may fall within the range: a row that starts before its end, and
     * whose series ends after its start. Widened by the zone margin for dates and floating times.
     *
     * @param list<string> $calendarIds
     *
     * @return list<CalendarObject>
     */
    public function objectsInRange(User $owner, array $calendarIds, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $margin = new \DateInterval(self::ZONE_MARGIN);
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('r')
            ->from(CalendarObject::class, 'r')
            ->join('r.calendar', 'c')
            ->where('c.owner = :owner')
            ->andWhere('EXISTS (SELECT 1 FROM '.CalendarEvent::class.' e WHERE e.object = r AND e.startsAt < :to AND (e.seriesEndsAt IS NULL OR e.seriesEndsAt > :from))')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->setParameter('from', $from->sub($margin))
            ->setParameter('to', $to->add($margin))
            ->orderBy('r.id');
        if ([] !== $calendarIds) {
            $qb->andWhere('c.id IN (:calendars)')->setParameter('calendars', array_map(static fn (string $id): string => Uuid::fromString($id)->toRfc4122(), $calendarIds));
        }

        /** @var list<CalendarObject> */
        return $qb->getQuery()->getResult();
    }
}
