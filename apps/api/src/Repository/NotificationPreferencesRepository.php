<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NotificationPreferences;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationPreferences>
 */
class NotificationPreferencesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationPreferences::class);
    }

    /** The user's preferences, created with the defaults when missing. */
    public function forUser(User $user): NotificationPreferences
    {
        $preferences = $this->find($user);
        if (null === $preferences) {
            $preferences = new NotificationPreferences($user);
            $this->getEntityManager()->persist($preferences);
        }

        return $preferences;
    }

    /**
     * Users whose notifications are planned less far ahead than $horizon (or not at all) and who have calendars.
     *
     * @return list<string> user ids
     */
    public function usersToPlan(\DateTimeImmutable $horizon, int $limit): array
    {
        /** @var list<string> */
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT u.id FROM app_user u LEFT JOIN notification_preferences p ON p.user_id = u.id
             WHERE (p.planned_until IS NULL OR p.planned_until < :horizon) AND EXISTS (SELECT 1 FROM calendar c WHERE c.owner_id = u.id)
             ORDER BY p.planned_until NULLS FIRST LIMIT :limit',
            ['horizon' => $horizon->format('Y-m-d H:i:s'), 'limit' => $limit],
        );
    }
}
