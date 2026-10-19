<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CalendarInvitation;
use App\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CalendarInvitation>
 */
class CalendarInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarInvitation::class);
    }

    /** @return list<CalendarInvitation> */
    public function findForMessage(Message $message): array
    {
        return $this->findBy(['message' => $message], ['createdAt' => 'ASC']);
    }
}
