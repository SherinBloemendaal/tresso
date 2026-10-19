<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Message;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Returns the message only when its account belongs to the owner, so other users' mail looks like it does not exist.
     */
    public function findOneOwnedBy(User $owner, string $id): ?Message
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $message = $this->createQueryBuilder('m')
            ->join('m.mailAccount', 'a')
            ->where('m.id = :id')
            ->andWhere('a.owner = :owner')
            ->setParameter('id', Uuid::fromString($id), 'uuid')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $message instanceof Message ? $message : null;
    }
}
