<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Attachment;
use App\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Attachment>
 */
class AttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Attachment::class);
    }

    /** @return list<Attachment> */
    public function findByMessage(Message $message): array
    {
        /** @var list<Attachment> */
        return $this->createQueryBuilder('a')
            ->where('a.message = :message')
            ->setParameter('message', $message->getId(), 'uuid')
            ->orderBy('a.inline')
            ->addOrderBy('a.filename')
            ->getQuery()
            ->getResult();
    }

    public function findById(string $id): ?Attachment
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->find(Uuid::fromString($id));
    }
}
