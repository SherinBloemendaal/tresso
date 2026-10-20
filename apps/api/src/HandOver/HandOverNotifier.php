<?php

declare(strict_types=1);

namespace App\HandOver;

use App\Entity\HandOver;
use App\Entity\NotificationDelivery;
use App\Entity\NotificationDevice;
use App\Enum\NotificationKind;
use App\Notification\CalendarPresence;
use App\Notification\Channel\PushMessage;
use App\Notification\NotificationChannelChoice;
use App\Notification\NotificationDispatcher;
use App\Notification\QuietHours;
use App\Realtime\AfterCommitDispatcher;
use App\Repository\NotificationPreferencesRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Tells the user that nobody replied to a hand-over by its due date, the way new mail is told
 * ({@see \App\Notification\MailNotifier}): the server decides, under a lock on the hand-over. It must still be open
 * and overdue; a focus block (holdDuringFocus) makes the notice wait for its end, quiet hours and other busy events
 * (focusDuringBusy) make it silent. It goes out on the channels the user chose for `hand_over` (channelsByKind, else
 * the phone and the desktop) through the {@see NotificationDispatcher}, keyed "handOver:{id}:{overdue at}" and claimed
 * in the transaction that records it on the hand-over (`notifiedAt`, `notifiedChannels`, `notifiedSilently`), which is
 * how the delta brings it to the desktop app. A new due date starts a new round. The sweep delivers what a lost message
 * missed and sends failed deliveries again.
 */
final readonly class HandOverNotifier
{
    /** Failed deliveries are not sent again later than this after the notice. */
    public const string MAX_LATE = '2 hours';
    /** A notice is only delivered this close to its time; a later one waits for its own message. */
    private const int EARLY_SECONDS = 30;
    /** How long push services keep trying. */
    private const string EXPIRES = '+1 day';
    private const int SWEEP_BATCH = 200;
    /** A hand-over whose notice failed to go out is tried again after this. */
    private const string FIRE_BACKOFF = '+5 minutes';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private NotificationPreferencesRepository $preferences,
        private NotificationDispatcher $dispatcher,
        private CalendarPresence $presence,
        private AfterCommitDispatcher $afterCommit,
        private ManagerRegistry $managers,
        private LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler]
    public function onNotify(NotifyOverdueHandOver $message): void
    {
        $this->deliver($message->handOverId, new \DateTimeImmutable());
    }

    #[AsMessageHandler]
    public function onSweep(SweepHandOverNotices $message): void
    {
        $this->sweep(new \DateTimeImmutable());
    }

    public function deliver(string $handOverId, \DateTimeImmutable $now): void
    {
        if (!Uuid::isValid($handOverId)) {
            return;
        }
        /** @var array{?HandOver, list<array{string, ?NotificationDevice, ?string}>} $next */
        $next = $this->entityManager->wrapInTransaction(function () use ($handOverId, $now): array {
            [$deliver, $retry, $waitUntil] = $this->prepare(Uuid::fromString($handOverId), $now);
            // Claimed in the transaction that records the notice: a crash before the sends leaves claimed rows, which
            // the sweep tries again, never a recorded notice nobody sends.
            $claimed = null === $deliver ? [] : $this->dispatcher->claim($deliver->getOwner(), self::deliveryKey($deliver), NotificationChannelChoice::fromValues($deliver->getNotifiedChannels()), !$retry);
            if (null !== $waitUntil) {
                $this->afterCommit->dispatch(new NotifyOverdueHandOver($handOverId), [new DelayStamp(max(0, ($waitUntil->getTimestamp() - $now->getTimestamp()) * 1000))]);
            }

            return [$deliver, $claimed];
        });
        [$deliver, $claimed] = $next;
        if (null !== $deliver && [] !== $claimed) {
            $this->dispatcher->deliver($claimed, $this->pushMessage($deliver, $now), self::deliveryKey($deliver), $now);
        }
        $this->entityManager->clear();
    }

    /**
     * Delivers due notices whose message got lost (or whose hold ended), and sends failed or stuck deliveries again.
     * Each on its own: one that fails is logged and waits FIRE_BACKOFF, the others go on.
     */
    public function sweep(\DateTimeImmutable $now): void
    {
        $connection = $this->entityManager->getConnection();
        /** @var list<string> $due */
        $due = $connection->fetchFirstColumn(
            "SELECT id FROM hand_over WHERE state = 'open' AND overdue_at IS NOT NULL AND notified_at IS NULL AND notify_at <= :now ORDER BY notify_at LIMIT :limit",
            ['now' => $now->format('Y-m-d H:i:s'), 'limit' => self::SWEEP_BATCH],
        );
        /** @var list<string> $retry */
        $retry = $connection->fetchFirstColumn(
            "SELECT DISTINCT h.id FROM hand_over h
             JOIN notification_delivery d ON d.delivery_key = 'handOver:' || h.id || ':' || EXTRACT(EPOCH FROM h.overdue_at)::BIGINT
             WHERE h.state = 'open' AND h.notified_at > :latest
               AND ((d.status = :failed AND d.attempts < :max AND d.updated_at <= CAST(:now AS TIMESTAMP) - INTERVAL '1 minute' * POWER(2, d.attempts - 1))
                 OR (d.status = :sending AND d.updated_at <= CAST(:now AS TIMESTAMP) - CAST(:stuck AS INTERVAL)))
             LIMIT :limit",
            [
                'now' => $now->format('Y-m-d H:i:s'), 'latest' => $now->modify('-'.self::MAX_LATE)->format('Y-m-d H:i:s'), 'limit' => self::SWEEP_BATCH,
                'failed' => NotificationDelivery::FAILED, 'sending' => NotificationDelivery::SENDING, 'max' => NotificationDispatcher::MAX_ATTEMPTS, 'stuck' => NotificationDispatcher::STUCK,
            ],
        );
        foreach (array_unique([...$due, ...$retry]) as $id) {
            try {
                $this->deliver($id, $now);
            } catch (\Throwable $e) {
                $this->logger->error('Telling about an overdue hand-over failed', ['handOver' => $id, 'error' => $e->getMessage()]);
                $this->managers->resetManager();
                $this->entityManager->getConnection()->executeStatement(
                    'UPDATE hand_over SET notify_at = :later WHERE id = :id AND notified_at IS NULL',
                    ['id' => $id, 'later' => $now->modify(self::FIRE_BACKOFF)->format('Y-m-d H:i:s')],
                );
            }
        }
    }

    /** One per round: a new due date that passes tells again, a retry of the same round never twice. */
    public static function deliveryKey(HandOver $handOver): string
    {
        $overdueAt = $handOver->getOverdueAt() ?? throw new \LogicException('Only an overdue hand-over is told about.');

        return \sprintf('handOver:%s:%d', $handOver->getId()->toRfc4122(), $overdueAt->getTimestamp());
    }

    /**
     * What to do now: the hand-over whose notice to send (recorded as delivered), and whether that is a retry of an
     * earlier delivery; or the time it now waits until (a focus block); or nothing.
     *
     * @return array{?HandOver, bool, ?\DateTimeImmutable}
     */
    private function prepare(Uuid $id, \DateTimeImmutable $now): array
    {
        $handOver = $this->entityManager->find(HandOver::class, $id, LockMode::PESSIMISTIC_WRITE);
        // Withdrawn, answered or done since, or given a new due date: nothing to tell any more.
        if (!$handOver instanceof HandOver || !$handOver->isOpen() || null === $handOver->getOverdueAt()) {
            return [null, false, null];
        }
        $notifiedAt = $handOver->getNotifiedAt();
        if (null !== $notifiedAt) {
            // Send again what failed or got stuck; the dispatcher leaves what went out alone.
            return [$notifiedAt > $now->modify('-'.self::MAX_LATE) ? $handOver : null, true, null];
        }
        $notifyAt = $handOver->getNotifyAt() ?? $handOver->getOverdueAt();
        if ($notifyAt->getTimestamp() > $now->getTimestamp() + self::EARLY_SECONDS) {
            return [null, false, null];
        }

        $owner = $handOver->getOwner();
        $preferences = $this->preferences->forUser($owner);
        $channels = $preferences->channelsFor(NotificationKind::HandOver->value, NotificationKind::HandOver->defaultChannels());
        if (!$channels->isEmpty() && $preferences->isHoldDuringFocus()) {
            $holdUntil = $this->presence->focusBlockEnd($owner, $now);
            if (null !== $holdUntil && $holdUntil > $now) {
                $handOver->postponeNotice($holdUntil);
                $this->entityManager->flush();

                return [null, false, $holdUntil];
            }
        }
        $silent = null !== QuietHours::endOf($preferences, $now)
            || ($preferences->isFocusDuringBusy() && $this->presence->inBusyEvent($owner, $now));
        // With no channel chosen the decision is recorded too, so clients do not tell about it either.
        $handOver->noticeDelivered($now, $channels->values(), $silent);
        $this->entityManager->flush();

        return [$channels->isEmpty() ? null : $handOver, false, null];
    }

    private function pushMessage(HandOver $handOver, \DateTimeImmutable $now): PushMessage
    {
        $id = $handOver->getId()->toRfc4122();

        return new PushMessage(
            $id,
            NotificationKind::HandOver->value,
            self::title($handOver),
            self::body($handOver),
            '/waiting?nudge='.$id,
            $handOver->isNotifiedSilently(),
            $now->modify(self::EXPIRES),
            // The dispatcher adds the user it is for (userId), which a browser needs to show it.
            ['handOverId' => $id],
        );
    }

    public static function title(HandOver $handOver): string
    {
        return \sprintf('No reply from %s yet', $handOver->getDelegateName() ?? $handOver->getDelegateAddress());
    }

    public static function body(HandOver $handOver): string
    {
        return \sprintf('%s is past its due date. Nudge from Waiting on.', '' === $handOver->getSubject() ? '(no subject)' : $handOver->getSubject());
    }
}
