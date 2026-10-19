<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Signature;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Signature>
 */
class SignatureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Signature::class);
    }

    /** @return list<Signature> */
    public function findOwnedBy(User $owner): array
    {
        /** @var list<Signature> */
        return $this->createQueryBuilder('s')
            ->where('s.owner = :owner')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->orderBy('s.name')
            ->addOrderBy('s.id')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the signature only when it belongs to the owner, so other users' signatures look like they do not exist.
     */
    public function findOneOwnedBy(User $owner, string $id): ?Signature
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($id), 'owner' => $owner]);
    }
}
