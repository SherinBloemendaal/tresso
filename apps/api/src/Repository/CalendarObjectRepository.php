<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Calendar;
use App\Entity\CalendarObject;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CalendarObject>
 */
class CalendarObjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarObject::class);
    }

    public function findOneByUid(Calendar $calendar, string $uid): ?CalendarObject
    {
        return $this->findOneBy(['calendar' => $calendar, 'uid' => $uid]);
    }

    /**
     * Every resource of the user with this UID, in any of their calendars.
     *
     * @return list<CalendarObject>
     */
    public function findForOwnerByUid(User $owner, string $uid): array
    {
        /** @var list<CalendarObject> */
        return $this->createQueryBuilder('r')
            ->join('r.calendar', 'c')
            ->where('c.owner = :owner')
            ->andWhere('r.uid = :uid')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->setParameter('uid', $uid)
            ->orderBy('r.id')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<string> $remoteIds
     *
     * @return array<string, CalendarObject> by remote id
     */
    public function findByRemoteIds(Calendar $calendar, array $remoteIds): array
    {
        if ([] === $remoteIds) {
            return [];
        }
        $byRemoteId = [];
        /** @var list<CalendarObject> $objects */
        $objects = $this->createQueryBuilder('r')
            ->where('r.calendar = :calendar')
            ->andWhere('r.remoteId IN (:remoteIds)')
            ->setParameter('calendar', $calendar->getId(), 'uuid')
            ->setParameter('remoteIds', $remoteIds)
            ->getQuery()
            ->getResult();
        foreach ($objects as $object) {
            $byRemoteId[(string) $object->getRemoteId()] = $object;
        }

        return $byRemoteId;
    }

    /**
     * @return array<string, ?string> etag by remote id, of every resource the provider has
     */
    public function remoteVersions(Calendar $calendar): array
    {
        /** @var list<array{remote_id: string, etag: ?string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT remote_id, etag FROM calendar_object WHERE calendar_id = :calendar AND remote_id IS NOT NULL',
            ['calendar' => $calendar->getId()->toRfc4122()],
        );

        return array_column($rows, 'etag', 'remote_id');
    }

    public function lock(CalendarObject $object): CalendarObject
    {
        $this->getEntityManager()->lock($object, LockMode::PESSIMISTIC_WRITE);
        $this->getEntityManager()->refresh($object);

        return $object;
    }
}
