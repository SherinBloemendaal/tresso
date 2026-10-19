<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Entity\Calendar;
use App\Entity\User;
use App\Repository\CalendarRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Every user has at least one Tresso calendar. New users get it when provisioned; users from before calendars get it
 * the first time something needs it, so nothing has to be backfilled.
 */
final readonly class LocalCalendars
{
    public const string DEFAULT_NAME = 'Tresso';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CalendarRepository $calendars,
    ) {
    }

    /**
     * The user's default Tresso calendar, or another Tresso calendar, created when the user has none. Persisted and
     * flushed when created.
     */
    public function ensure(User $user): Calendar
    {
        $existing = $this->find($user);
        if (null !== $existing) {
            return $existing;
        }

        return $this->entityManager->wrapInTransaction(function () use ($user): Calendar {
            // Two requests at once must not both create one: the second waits here and then finds the first's.
            $this->entityManager->lock($user, LockMode::PESSIMISTIC_WRITE);
            $existing = $this->find($user);
            if (null !== $existing) {
                return $existing;
            }
            $calendar = self::create($user);
            $this->entityManager->persist($calendar);
            $this->entityManager->flush();

            return $calendar;
        });
    }

    /**
     * The calendar a new user starts with; the caller persists it.
     */
    public static function create(User $user): Calendar
    {
        $calendar = Calendar::local($user, self::DEFAULT_NAME);
        $calendar->setDefault(true);

        return $calendar;
    }

    private function find(User $user): ?Calendar
    {
        return $this->calendars->findOneBy(['owner' => $user, 'mailAccount' => null, 'isDefault' => true]) ?? $this->calendars->findLocal($user);
    }
}
