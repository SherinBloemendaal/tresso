<?php

declare(strict_types=1);

namespace App\Brief;

use App\Classification\UserLocks;
use App\Entity\DailyBrief;
use App\Entity\DailyBriefSettings;
use App\Entity\User;
use App\Realtime\AfterCommitDispatcher;
use App\Repository\DailyBriefRepository;
use App\Repository\DailyBriefSettingsRepository;
use App\Repository\NotificationPreferencesRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Makes the daily briefs that are due: per user, the conversations judged `know` since the previous brief that are
 * still in an inbox. A brief with nothing in it is not made, but the period still counts as covered.
 *
 * Each user is handled in its own transaction that holds their settings row, so two sweeps never make the same brief
 * and a failure costs one user one run, which the next sweep repeats.
 */
final readonly class DailyBriefs
{
    /** Briefs kept per user; older ones are deleted (clients drop them through the delta). */
    public const int KEEP = 14;

    /** Conversations in one brief at most, newest first. */
    public const int MAX_ENTRIES = 200;

    /** Users handled per sweep; the rest wait for the next minute. */
    public const int USERS_PER_SWEEP = 100;

    /** A user whose brief failed is tried again this much later. */
    public const string RETRY_AFTER = '+15 minutes';

    /**
     * How far back a brief reaches: after a long pause (brief turned off, a worker down) it covers this much, and mail
     * received before it stays out also when it was classified late (a first sync of an old mailbox).
     */
    private const string REACH = '-7 days';

    /** What the first brief of a user covers. */
    private const string FIRST_PERIOD = '-1 day';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private DailyBriefSettingsRepository $settings,
        private DailyBriefRepository $briefs,
        private AfterCommitDispatcher $afterCommit,
        private UserLocks $locks,
        private NotificationPreferencesRepository $preferences,
        private ManagerRegistry $doctrine,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Makes the briefs due at $now, for at most {@see USERS_PER_SWEEP} users. A user whose brief fails is logged and
     * put off for {@see RETRY_AFTER}, so one broken account never holds up the others.
     *
     * @return int how many users got their brief made (or found nothing to make)
     */
    public function sweep(\DateTimeImmutable $now): int
    {
        $handled = 0;
        for ($claimed = 0; $claimed < self::USERS_PER_SWEEP; ++$claimed) {
            $userId = null;
            try {
                $done = $this->entityManager->wrapInTransaction(function () use ($now, &$userId): bool {
                    $userId = $this->settings->claimDue($now);
                    if (null === $userId) {
                        return false;
                    }
                    $settings = $this->settings->find($userId) ?? throw new \LogicException('The claimed brief settings are gone.');
                    $this->make($settings, $now);

                    return true;
                });
            } catch (\Throwable $error) {
                if (null === $userId) {
                    throw $error;
                }
                $this->logger->error('The daily brief of a user could not be made', ['user' => $userId->toRfc4122(), 'exception' => $error]);
                // A failed flush closes the entity manager; the next user gets a fresh one.
                $this->doctrine->resetManager();
                $this->settings->postpone($userId, $now->modify(self::RETRY_AFTER));
                continue;
            } finally {
                if ($this->entityManager->isOpen()) {
                    $this->entityManager->clear();
                }
            }
            if (!$done) {
                break;
            }
            ++$handled;
        }

        return $handled;
    }

    /**
     * Makes the brief of the period since the last one, up to $now, and plans the next. Runs inside the caller's
     * transaction, which must hold the settings row (see {@see DailyBriefSettingsRepository::claimDue()}).
     */
    public function make(DailyBriefSettings $settings, \DateTimeImmutable $now): ?DailyBrief
    {
        $user = $settings->getUser();
        // Classification runs of the user that started before now have committed once this lock is ours; those after
        // it stamp a later time. Times are stored in whole seconds, so the brief ends before the second now is in: mail
        // stamped in that second goes to the next one.
        $this->locks->classification($user->getId());
        $until = new \DateTimeImmutable('@'.($now->getTimestamp() - 1));
        $reach = $until->modify(self::REACH);
        $from = $settings->getCoveredUntil() ?? $until->modify(self::FIRST_PERIOD);
        if ($from < $reach) {
            $from = $reach;
        }

        $preferences = $this->preferences->forUser($user);
        if ($settings->isDueBriefMade($preferences->getTimeZone())) {
            // Another sweep made today's brief while this one waited, or the clock repeated the hour: one a day.
            $settings->reschedule($preferences->getTimeZone(), $now);
            $this->entityManager->flush();

            return null;
        }
        $threadIds = $this->knowThreads($user, $from, $until, $reach);
        $brief = null;
        if ([] !== $threadIds) {
            $brief = new DailyBrief($user, $from, $until, $threadIds, $now);
            $this->entityManager->persist($brief);
            foreach ($this->briefs->olderThanNewest($user, self::KEEP - 1) as $old) {
                $this->entityManager->remove($old);
            }
            if (!DailyBriefDelivery::channelsFor($preferences)->isEmpty()) {
                $this->afterCommit->dispatch(new DeliverDailyBrief($brief->getId()->toRfc4122()));
            }
        }
        $settings->ran($until, $preferences->getTimeZone(), $now);
        $this->entityManager->flush();

        return $brief;
    }

    /**
     * The user's conversations judged `know` now, with a message judged `know` in the period that arrived within
     * reach, and still in an inbox. Newest first.
     *
     * @return list<string>
     */
    private function knowThreads(User $user, \DateTimeImmutable $from, \DateTimeImmutable $until, \DateTimeImmutable $reach): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            "SELECT t.id FROM thread t JOIN mail_account a ON a.id = t.mail_account_id
             WHERE a.owner_id = :user AND t.verdict = 'know'
               AND EXISTS (
                   SELECT 1 FROM message m WHERE m.thread_id = t.id AND m.verdict = 'know'
                     AND m.classified_at > :from AND m.classified_at <= :until AND m.received_at > :reach
               )
               AND EXISTS (
                   SELECT 1 FROM message m JOIN message_location l ON l.message_id = m.id JOIN folder f ON f.id = l.folder_id
                   WHERE m.thread_id = t.id AND f.role = 'inbox'
               )
             ORDER BY t.last_message_at DESC, t.id DESC
             LIMIT :limit",
            [
                'user' => $user->getId()->toRfc4122(),
                'from' => self::utc($from),
                'until' => self::utc($until),
                'reach' => self::utc($reach),
                'limit' => self::MAX_ENTRIES,
            ],
        );
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
