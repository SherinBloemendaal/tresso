<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CalendarEvent;
use App\Entity\EventNote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventNote>
 */
class EventNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventNote::class);
    }

    public function findFor(CalendarEvent $event, string $occurrenceKey): ?EventNote
    {
        return $this->findOneBy(['event' => $event, 'occurrenceKey' => $occurrenceKey]);
    }
}
