<?php

declare(strict_types=1);

namespace App\Batch;

use App\Classification\UserLocks;
use App\Entity\BatchSchedule;
use App\Entity\Category;
use App\Entity\Message;
use App\Entity\Thread;
use App\Entity\User;
use App\Notification\MailNotifier;
use App\Realtime\AfterCommitDispatcher;
use App\Realtime\TransactionListener;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Batched delivery: new mail of a smart inbox that delivers in batches waits for the user's next batch time
 * (`held_until` on the message and its conversation), and is released at that time (`batched_at`). Clients keep held
 * mail out of their lists and notifications, so it shows once its batch is out.
 *
 * Releases are announced as delayed Messenger messages for the batch time, dispatched after the commit; a sweep every
 * minute ({@see SweepBatches}) releases what is due as well, whether or not a worker leases the account, so a lost
 * message only delays a batch. Every method runs inside the caller's transaction.
 */
final class BatchDelivery implements TransactionListener, ResetInterface
{
    /** Held mail released per transaction. */
    public const int RELEASE_BATCH = 500;

    /** @var array<string, true> releases announced in this transaction, by user and time */
    private array $announced = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly AfterCommitDispatcher $afterCommit,
        private readonly UserLocks $locks,
        private readonly MailNotifier $mailNotifier,
    ) {
    }

    /** The user's batch times: their own, or the defaults. */
    public function times(User $user): BatchTimes
    {
        return $this->schedule($user)?->batchTimes() ?? BatchTimes::defaults();
    }

    public function schedule(User $user): ?BatchSchedule
    {
        return $this->entityManager->getRepository(BatchSchedule::class)->findOneBy(['user' => $user]);
    }

    /**
     * Holds a message that was just classified for the first time, when its smart inbox delivers in batches and it is
     * new to the user: unread, and received after the last batch went out (older mail belongs to a batch that is gone
     * and is delivered as it comes). Returns whether it is held.
     */
    public function holdNew(Message $message, Category $smartInbox, BatchTimes $times, \DateTimeImmutable $now): bool
    {
        if (!$smartInbox->isBatchDelivery() || $message->isSeen() || $message->getReceivedAt() <= $times->previous($now)) {
            return false;
        }
        $thread = $message->getThread();
        if (null !== $thread->getClassifiedAt() && null === $thread->getHeldUntil() && $message->getReceivedAt() < $thread->getLastMessageAt()) {
            // An older message synced late into a conversation that is already in sight: holding it hides nothing.
            return false;
        }
        $until = $times->next($now);
        $message->hold($until);
        $this->announce($message->getMailAccount()->getOwner()->getId(), $until);

        return true;
    }

    /**
     * After a message moved to another smart inbox: it is delivered at once when that one does not deliver in batches.
     */
    public function moved(Message $message, Category $smartInbox): void
    {
        if ($message->isHeld() && !$smartInbox->isBatchDelivery()) {
            $message->release(null);
        }
    }

    /** Delivers the held mail of a smart inbox at once, e.g. because it no longer delivers in batches. */
    public function releaseSmartInbox(Category $smartInbox): void
    {
        foreach ([Message::class, Thread::class] as $class) {
            /** @var list<Message|Thread> $held */
            $held = $this->entityManager->createQueryBuilder()
                ->select('x')->from($class, 'x')
                ->where('x.category = :smartInbox')->andWhere('x.heldUntil IS NOT NULL')
                ->setParameter('smartInbox', $smartInbox->getId(), 'uuid')
                ->getQuery()->getResult();
            foreach ($held as $entity) {
                $entity->release(null);
            }
        }
        $this->entityManager->flush();
    }

    /** Moves all of the user's held mail to the next batch of a changed schedule, however much waits. */
    public function reschedule(User $user, BatchTimes $times, \DateTimeImmutable $now): void
    {
        $until = $times->next($now);
        $moved = false;
        foreach ([Message::class, Thread::class] as $class) {
            // Each part leaves the ones it moved out of the next query, which takes those still due elsewhere.
            do {
                $part = $this->heldOf($class, $user->getId(), null, $until);
                foreach ($part as $entity) {
                    $entity->hold($until);
                    $moved = true;
                }
                $this->entityManager->flush();
            } while (\count($part) >= self::RELEASE_BATCH);
        }
        if ($moved) {
            $this->announce($user->getId(), $until);
        }
    }

    /**
     * Delivers a conversation the user placed: every held message of it, so none arrives (and notifies) later with
     * its batch. The user is looking at it, so it does not notify now either (see {@see delivered()}).
     */
    public function releaseThread(Thread $thread): void
    {
        $thread->release(null);
        /** @var list<Message> $held */
        $held = $this->entityManager->createQueryBuilder()
            ->select('m')->from(Message::class, 'm')
            ->where('m.thread = :thread')->andWhere('m.heldUntil IS NOT NULL')
            ->setParameter('thread', $thread->getId(), 'uuid')
            ->getQuery()->getResult();
        foreach ($held as $message) {
            $message->release(null);
        }
    }

    /**
     * Releases the user's mail whose batch time has come, at most {@see RELEASE_BATCH} messages and conversations of
     * each. Takes the user's classification lock, so a classification run never holds what this releases.
     *
     * @return bool whether more may be due (run again in a new transaction)
     */
    public function releaseDue(Uuid $userId, \DateTimeImmutable $now): bool
    {
        $this->locks->classification($userId);
        $more = false;
        $messages = [];
        foreach ([Message::class, Thread::class] as $class) {
            $due = $this->heldOf($class, $userId, $now);
            foreach ($due as $entity) {
                $entity->release($entity->getHeldUntil());
                if ($entity instanceof Message) {
                    $messages[] = $entity;
                }
            }
            $more = $more || \count($due) >= self::RELEASE_BATCH;
        }
        $this->entityManager->flush();
        $this->delivered($messages, $now);

        return $more;
    }

    /**
     * Users with mail due for release, at most $limit of them, longest overdue first.
     *
     * @return list<string>
     */
    public function usersWithDueMail(\DateTimeImmutable $now, int $limit): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT owner_id FROM (
                SELECT a.owner_id, m.held_until FROM message m JOIN mail_account a ON a.id = m.mail_account_id WHERE m.held_until <= :now
                UNION ALL
                SELECT a.owner_id, t.held_until FROM thread t JOIN mail_account a ON a.id = t.mail_account_id WHERE t.held_until <= :now
            ) due GROUP BY owner_id ORDER BY MIN(held_until) LIMIT :limit',
            ['now' => $now->format('Y-m-d H:i:s'), 'limit' => $limit],
        );
    }

    /**
     * Mail whose batch came notifies now, as new mail does when it arrives (see {@see MailNotifier::released()}): once,
     * and only when it still needs the user. Mail delivered early by the user's own doing (a correction, a changed
     * rule or smart inbox, batches switched off) does not: like classification that runs again, it would push a burst
     * while the user is busy in Tresso.
     *
     * @param list<Message> $messages
     */
    private function delivered(array $messages, \DateTimeImmutable $now): void
    {
        $byOwner = [];
        foreach ($messages as $message) {
            $owner = $message->getMailAccount()->getOwner();
            $byOwner[$owner->getId()->toRfc4122()][0] = $owner;
            $byOwner[$owner->getId()->toRfc4122()][1][] = $message;
        }
        foreach ($byOwner as [$owner, $ownMessages]) {
            $this->mailNotifier->released($owner, $ownMessages, $now);
        }
    }

    /**
     * Held messages or conversations of a user, a part at a time; with $dueAt only those whose batch time has come by
     * then, with $notUntil only those held for another time.
     *
     * @template T of Message|Thread
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function heldOf(string $class, Uuid $userId, ?\DateTimeImmutable $dueAt, ?\DateTimeImmutable $notUntil = null): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('x')->from($class, 'x')->join('x.mailAccount', 'a')
            ->where('a.owner = :user')->andWhere('x.heldUntil IS NOT NULL')
            ->setParameter('user', $userId, 'uuid')
            ->orderBy('x.heldUntil')->addOrderBy('x.id')
            ->setMaxResults(self::RELEASE_BATCH);
        if (null !== $dueAt) {
            $query->andWhere('x.heldUntil <= :now')->setParameter('now', $dueAt);
        }
        if (null !== $notUntil) {
            $query->andWhere('x.heldUntil <> :until')->setParameter('until', $notUntil);
        }

        /** @var list<T> */
        return $query->getQuery()->getResult();
    }

    public function transactionCommitted(): void
    {
        $this->announced = [];
    }

    public function transactionRolledBack(): void
    {
        $this->announced = [];
    }

    public function reset(): void
    {
        $this->announced = [];
    }

    /** Releases the user's mail at $at, once the transaction committed; once per user and time in a transaction. */
    private function announce(Uuid $userId, \DateTimeImmutable $at): void
    {
        $key = $userId->toRfc4122().'@'.$at->getTimestamp();
        if (isset($this->announced[$key])) {
            return;
        }
        $this->announced[$key] = true;
        $this->afterCommit->dispatch(new ReleaseBatch($userId->toRfc4122()), [DelayStamp::delayUntil($at)]);
    }
}
