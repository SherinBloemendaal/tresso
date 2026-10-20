<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SchedulingPreferences;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchedulingPreferences>
 */
class SchedulingPreferencesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchedulingPreferences::class);
    }

    /**
     * The user's preferences, or the defaults (not persisted) when they never changed them.
     */
    public function forUser(User $user): SchedulingPreferences
    {
        return $this->findOneBy(['user' => $user]) ?? new SchedulingPreferences($user);
    }

    /**
     * The user's preferences to change, locked until the transaction ends and read as they are then. The row is created
     * with the defaults when missing, and two first changes at the same moment both find the one row instead of both
     * creating it.
     */
    public function lockForUser(User $user): SchedulingPreferences
    {
        $defaults = new SchedulingPreferences($user);
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO scheduling_preferences (user_id, buffer_minutes, travel_minutes, minimum_notice_minutes, updated_at)
             VALUES (:user, :buffer, :travel, :notice, :updated) ON CONFLICT (user_id) DO NOTHING',
            [
                'user' => $user->getId()->toRfc4122(),
                'buffer' => $defaults->getBufferMinutes(),
                'travel' => $defaults->getTravelMinutes(),
                'notice' => $defaults->getMinimumNoticeMinutes(),
                'updated' => $defaults->getUpdatedAt(),
            ],
            ['updated' => Types::DATETIME_IMMUTABLE],
        );

        // Refreshed: this request may have loaded the row before another request changed it and released the lock.
        /** @var SchedulingPreferences */
        return $this->createQueryBuilder('p')
            ->where('p.user = :user')
            ->setParameter('user', $user->getId(), 'uuid')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getSingleResult();
    }
}
