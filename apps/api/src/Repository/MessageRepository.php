<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Message;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
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

    /**
     * The owner's messages among $ids, keyed by id, locked for the caller's transaction; other users' mail is left out
     * as if it did not exist.
     *
     * @param list<string> $ids valid uuids
     *
     * @return array<string, Message>
     */
    public function lockOwnedBy(User $owner, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Message> $messages */
        $messages = $this->createQueryBuilder('m')
            ->join('m.mailAccount', 'a')
            ->where('m.id IN (:ids)')
            ->andWhere('a.owner = :owner')
            ->setParameter('ids', array_map(static fn (string $id): string => Uuid::fromString($id)->toRfc4122(), $ids))
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->orderBy('m.id')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        $byId = [];
        foreach ($messages as $message) {
            $byId[$message->getId()->toRfc4122()] = $message;
        }

        return $byId;
    }
}
