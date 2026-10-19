<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * The owner's smart inboxes in sidebar order.
     *
     * @return list<Category>
     */
    public function findOwnedBy(User $owner): array
    {
        /** @var list<Category> */
        return $this->createQueryBuilder('c')
            ->where('c.owner = :owner')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->orderBy('c.position')
            ->addOrderBy('c.id')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the smart inbox only when it belongs to the owner, so other users' smart inboxes look like they do not
     * exist.
     */
    public function findOneOwnedBy(User $owner, string $id): ?Category
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($id), 'owner' => $owner]);
    }
}
