<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProviderGrant;
use App\Entity\User;
use App\Enum\OAuthProvider;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProviderGrant>
 */
class ProviderGrantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProviderGrant::class);
    }

    public function findOneFor(User $owner, OAuthProvider $provider, string $subject): ?ProviderGrant
    {
        return $this->findOneBy(['owner' => $owner, 'provider' => $provider, 'subject' => $subject]);
    }
}
