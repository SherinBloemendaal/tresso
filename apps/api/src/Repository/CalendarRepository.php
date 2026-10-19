<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Calendar;
use App\Entity\MailAccount;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Calendar>
 */
class CalendarRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Calendar::class);
    }

    /**
     * The local calendar first, then each account's calendars in the provider's order.
     *
     * @return list<Calendar>
     */
    public function findOwnedBy(User $owner): array
    {
        /** @var list<Calendar> */
        return $this->createQueryBuilder('c')
            ->leftJoin('c.mailAccount', 'a')
            ->where('c.owner = :owner')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->orderBy('a.createdAt')
            ->addOrderBy('c.position')
            ->addOrderBy('c.createdAt')
            ->getQuery()
            ->getResult();
    }

    public function findOneOwnedBy(User $owner, string $id): ?Calendar
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($id), 'owner' => $owner]);
    }

    public function findLocal(User $owner): ?Calendar
    {
        return $this->findOneBy(['owner' => $owner, 'mailAccount' => null]);
    }

    /**
     * @return array<string, Calendar> by remote id
     */
    public function findByAccount(MailAccount $account): array
    {
        $byRemoteId = [];
        foreach ($this->findBy(['mailAccount' => $account]) as $calendar) {
            $byRemoteId[(string) $calendar->getRemoteId()] = $calendar;
        }

        return $byRemoteId;
    }

    public function findDefaultFor(MailAccount $account): ?Calendar
    {
        return $this->findOneBy(['mailAccount' => $account, 'isDefault' => true]);
    }
}
