<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NotificationDevice;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<NotificationDevice>
 */
class NotificationDeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationDevice::class);
    }

    /** @return list<NotificationDevice> */
    public function findOwnedBy(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'ASC']);
    }

    /** @return list<NotificationDevice> */
    public function findEnabled(User $user): array
    {
        return $this->findBy(['user' => $user, 'enabled' => true], ['createdAt' => 'ASC']);
    }

    public function findOneOwnedBy(User $user, string $id): ?NotificationDevice
    {
        return Uuid::isValid($id) ? $this->findOneBy(['id' => Uuid::fromString($id), 'user' => $user]) : null;
    }

    /**
     * @return list<NotificationDevice>
     */
    public function findOfOthersByEndpoint(User $user, string $endpoint): array
    {
        /** @var list<NotificationDevice> */
        return $this->createQueryBuilder('d')
            ->where('d.endpoint = :endpoint')
            ->andWhere('d.user != :user')
            ->setParameter('endpoint', $endpoint)
            ->setParameter('user', $user->getId(), 'uuid')
            ->getQuery()
            ->getResult();
    }

    public function findByEndpoint(User $user, string $endpoint): ?NotificationDevice
    {
        return $this->findOneBy(['user' => $user, 'endpoint' => $endpoint]);
    }
}
