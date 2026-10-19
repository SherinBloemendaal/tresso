<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\CalendarObject;
use App\Entity\User;
use App\Entity\NotificationDelivery;
use App\Repository\NotificationPreferencesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * The Messenger side of notifications: planning after changes, firing at the planned time, and the sweep that
 * catches whatever a lost message would have missed.
 */
final readonly class NotificationHandlers
{
    private const int SWEEP_BATCH = 200;
    private const int PLAN_BATCH = 50;
    /** Plans are extended for users whose plan ends sooner than this. */
    private const string PLAN_AHEAD = 'P7D';
    /** Due notifications older than this are too late to send (NotificationDeliverer::MAX_LATE). */
    private const string LATEST = '-'.NotificationDeliverer::MAX_LATE;
    /** A user whose planning failed is tried again after this. */
    private const string PLAN_BACKOFF = '+1 hour';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private NotificationPlanner $planner,
        private NotificationDeliverer $deliverer,
        private NotificationPreferencesRepository $preferences,
        private NotificationDispatcher $dispatcher,
        private ManagerRegistry $managers,
        private LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler]
    public function plan(PlanCalendarObject $message): void
    {
        $now = new \DateTimeImmutable();
        $this->entityManager->wrapInTransaction(function () use ($message, $now): void {
            $object = Uuid::isValid($message->objectId) ? $this->entityManager->find(CalendarObject::class, Uuid::fromString($message->objectId)) : null;
            if (null !== $object) {
                $this->planner->planObject($object, $now);

                return;
            }
            $user = Uuid::isValid($message->userId) ? $this->entityManager->find(User::class, Uuid::fromString($message->userId)) : null;
            if (null !== $user) {
                $this->planner->removeOrphans($user);
            }
        });
        $this->entityManager->clear();
    }

    #[AsMessageHandler]
    public function planUser(PlanUserNotifications $message): void
    {
        $now = new \DateTimeImmutable();
        $this->entityManager->wrapInTransaction(function () use ($message, $now): void {
            $user = Uuid::isValid($message->userId) ? $this->entityManager->find(User::class, Uuid::fromString($message->userId)) : null;
            if (null !== $user) {
                $this->planner->planUser($user, $now);
            }
        });
        $this->entityManager->clear();
    }

    #[AsMessageHandler]
    public function fire(FireNotification $message): void
    {
        $this->deliverer->fire($message->notificationId, new \DateTimeImmutable());
    }

    #[AsMessageHandler]
    public function sweep(SweepNotifications $message): void
    {
        $now = new \DateTimeImmutable();
        /** @var list<string> $due */
        $due = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id FROM scheduled_notification WHERE delivered_at IS NULL AND dismissed_at IS NULL AND fire_at <= :now AND fire_at > :latest ORDER BY fire_at LIMIT :limit',
            ['now' => $now->format('Y-m-d H:i:s'), 'latest' => $now->modify(self::LATEST)->format('Y-m-d H:i:s'), 'limit' => self::SWEEP_BATCH],
        );
        // Delivered ones whose send failed (and is due another try) or never reported back.
        /** @var list<string> $retry */
        $retry = $this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT DISTINCT n.id FROM scheduled_notification n
             JOIN notification_delivery d ON d.delivery_key = 'calendar:' || n.id || ':' || n.round
             WHERE n.delivered_at > :latest AND n.dismissed_at IS NULL
               AND ((d.status = :failed AND d.attempts < :max AND d.updated_at <= CAST(:now AS TIMESTAMP) - INTERVAL '1 minute' * POWER(2, d.attempts - 1))
                 OR (d.status = :sending AND d.updated_at <= CAST(:now AS TIMESTAMP) - CAST(:stuck AS INTERVAL)))
             LIMIT :limit",
            [
                'now' => $now->format('Y-m-d H:i:s'), 'latest' => $now->modify(self::LATEST)->format('Y-m-d H:i:s'), 'limit' => self::SWEEP_BATCH,
                'failed' => NotificationDelivery::FAILED, 'sending' => NotificationDelivery::SENDING, 'max' => NotificationDispatcher::MAX_ATTEMPTS, 'stuck' => NotificationDispatcher::STUCK,
            ],
        );
        foreach ([...$due, ...$retry] as $id) {
            $this->deliverer->fire($id, $now);
        }
        $this->dispatcher->checkReceipts($now);

        // Each user on their own: one that fails is logged and waits PLAN_BACKOFF, the others go on.
        foreach ($this->preferences->usersToPlan($now->add(new \DateInterval(self::PLAN_AHEAD)), $now, self::PLAN_BATCH) as $userId) {
            try {
                $this->planUser(new PlanUserNotifications($userId));
            } catch (\Throwable $e) {
                $this->logger->error('Planning notifications failed', ['user' => $userId, 'error' => $e->getMessage()]);
                $this->managers->resetManager();
                $this->preferences->backOff($userId, $now->modify(self::PLAN_BACKOFF));
            }
        }
    }
}
