<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SchedulingPreferences;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
