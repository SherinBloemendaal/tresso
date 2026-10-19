<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CalendarEvent;
use App\Entity\ScheduledNotification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ScheduledNotification>
 */
class ScheduledNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledNotification::class);
    }

    public function findOneOwnedBy(User $user, string $id): ?ScheduledNotification
    {
        return Uuid::isValid($id) ? $this->findOneBy(['id' => Uuid::fromString($id), 'user' => $user]) : null;
    }

    /**
     * Every notification of these events, delivered or not, by "event id|occurrence|kind|slot".
     *
     * @param list<CalendarEvent> $events
     *
     * @return array<string, ScheduledNotification>
     */
    public function findPlannedFor(array $events): array
    {
        if ([] === $events) {
            return [];
        }
        /** @var list<ScheduledNotification> $rows */
        $rows = $this->createQueryBuilder('n')
            ->where('n.event IN (:events)')
            ->setParameter('events', array_map(static fn (CalendarEvent $event): string => $event->getId()->toRfc4122(), $events))
            ->getQuery()
            ->getResult();
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[self::key($row)] = $row;
        }

        return $byKey;
    }

    public static function key(ScheduledNotification $notification): string
    {
        return implode('|', [$notification->getEvent()?->getId()->toRfc4122() ?? '', $notification->getOccurrenceKey(), $notification->getKind()->value, $notification->getSlot()]);
    }

    /**
     * Planned notifications whose event is gone.
     *
     * @return list<ScheduledNotification>
     */
    public function findOrphans(User $user): array
    {
        return $this->findBy(['user' => $user, 'event' => null, 'deliveredAt' => null]);
    }

    /**
     * Due and undelivered, locked so two sweeps never take the same one. Older than the cut-off they are too late to
     * matter and are left alone.
     *
     * @return list<ScheduledNotification>
     */
    public function lockDue(\DateTimeImmutable $now, \DateTimeImmutable $notBefore, int $limit): array
    {
        /** @var list<ScheduledNotification> */
        return $this->createQueryBuilder('n')
            ->where('n.deliveredAt IS NULL')
            ->andWhere('n.fireAt <= :now')
            ->andWhere('n.fireAt > :notBefore')
            ->setParameter('now', $now)
            ->setParameter('notBefore', $notBefore)
            ->orderBy('n.fireAt')
            ->setMaxResults($limit)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }

    /**
     * Recent and upcoming notifications for the in-app list.
     *
     * @return list<ScheduledNotification>
     */
    public function findForList(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<ScheduledNotification> */
        return $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->andWhere('n.fireAt >= :from AND n.fireAt < :to')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('n.fireAt')
            ->getQuery()
            ->getResult();
    }
}
