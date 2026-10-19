<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ReminderRule;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ReminderRule>
 */
class ReminderRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReminderRule::class);
    }

    /** @return list<ReminderRule> */
    public function findOwnedBy(User $user): array
    {
        return $this->findBy(['user' => $user], ['position' => 'ASC', 'id' => 'ASC']);
    }

    public function findOneOwnedBy(User $user, string $id): ?ReminderRule
    {
        return Uuid::isValid($id) ? $this->findOneBy(['id' => Uuid::fromString($id), 'user' => $user]) : null;
    }
}
