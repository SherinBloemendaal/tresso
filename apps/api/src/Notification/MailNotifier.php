<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\MailNotification;
use App\Entity\Message;
use App\Entity\NotificationDelivery;
use App\Entity\NotificationDevice;
use App\Entity\User;
use App\Enum\NotificationKind;
use App\Enum\Verdict;
use App\Notification\Channel\PushMessage;
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
 * Notifies the user of new mail that needs them: a message classified for the first time as `do` or `delegate`, unread,
 * in an inbox, and received recently (an account's history being fetched is not news). Classification calls
 * {@see consider()} in its own transaction, which keeps one {@see MailNotification} per message, so a message notifies
 * at most once, whatever runs again.
 *
 * Delivery happens after that commit, under a lock on the notification: the message must still need the user
 * ({@see needsTheUser()}); a focus block (holdDuringFocus) or a meeting (holdMailInMeetings) makes it wait
 * for their end, quiet hours and other busy events (focusDuringBusy) make it silent. It goes out on the channels the
 * user chose for mail (channelsByKind, else phone and desktop) through the {@see NotificationDispatcher}, keyed
 * "mail:{notification}", claimed in the transaction that marks it delivered. The sweep delivers what a lost message
 * missed and sends failed deliveries again.
 */
final readonly class MailNotifier
{
    /** Mail received longer ago than this when it is classified is not news. */
    public const string FRESH = '1 hour';
    /** Nothing is delivered later than this after its fire time, nor sent again after a failure. */
    public const string MAX_LATE = '2 hours';
    /** A notification is only delivered this close to its fire time; a later one is waiting for its own message. */
    private const int EARLY_SECONDS = 30;
    /** How long push services keep trying. */
    private const string EXPIRES = '+2 hours';
    private const int SWEEP_BATCH = 200;
    /** A notification whose delivery failed is tried again after this. */
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

    /**
     * Called by classification, in its transaction, with the messages it classified for the first time: new mail.
     * Classification that runs again (rules or a correction changed) never comes here: that mail is not new, and a
     * settings change would otherwise push a burst of it. Plans a notification for each message that needs the user
     * and was received recently. The same mail in two of the user's accounts (one Message-ID) notifies once: the
     * copies after the first get a notification that is dropped at once, so they never notify later either.
     *
     * @param list<Message> $classified
     */
    public function consider(User $user, array $classified, \DateTimeImmutable $now): void
    {
        $this->plan($user, array_values(array_filter($classified, static fn (Message $message): bool => $message->getReceivedAt() >= $now->modify('-'.self::FRESH))), $now);
    }

    /**
     * Called when a batch delivers held mail, in that transaction: it was new when it arrived, and its notification
     * waited with it. Plans one for each message that needs the user now, however long ago it was received; mail
     * notified before is not notified again. Mail the user's own doing delivers early never comes here, as
     * classification that runs again never does.
     *
     * @param list<Message> $released
     */
    public function released(User $user, array $released, \DateTimeImmutable $now): void
    {
        $this->plan($user, $released, $now);
    }

    /**
     * @param list<Message> $fresh
     */
    private function plan(User $user, array $fresh, \DateTimeImmutable $now): void
    {
        if ([] === $fresh) {
            return;
        }
        /** @var list<string> $notified */
        $notified = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT message_id FROM mail_notification WHERE message_id = ANY(CAST(:ids AS UUID[]))',
            ['ids' => '{'.implode(',', array_map(static fn (Message $message): string => $message->getId()->toRfc4122(), $fresh)).'}'],
        );
        // Without previews no sender, subject or text is kept, also in notifications that wait, are dropped or deduped.
        $previews = $this->preferences->forUser($user)->isMailPreviews();
        $headers = [];
        foreach ($fresh as $message) {
            // Classification holds the user's lock, so no other run plans the same message meanwhile.
            if (\in_array($message->getId()->toRfc4122(), $notified, true) || !$this->needsTheUser($message)) {
                continue;
            }
            $notification = new MailNotification($user, $message, self::title($message), self::body($message), $now);
            if (!$previews) {
                $notification->hideContent(self::withoutContent($message));
            }
            $this->entityManager->persist($notification);
            $header = $message->getMessageIdHeader();
            if (null !== $header && (isset($headers[$header]) || $this->notifiedRecently($user, $header, $now))) {
                $notification->drop($now);
                continue;
            }
            if (null !== $header) {
                $headers[$header] = true;
            }
            $this->afterCommit->dispatch(new SendMailNotification($notification->getId()->toRfc4122()));
        }
        $this->entityManager->flush();
    }

    /**
     * Whether a message needs the user now: judged `do` or `delegate`, unread, in an inbox, not waiting for a batch
     * (held mail notifies when its batch delivers it, see {@see released()}), and not in a conversation the user placed
     * themselves elsewhere (their placement wins over the message's own verdict). Checked when a notification is
     * planned and again when it is delivered.
     */
    private function needsTheUser(Message $message): bool
    {
        if ($message->isSeen() || $message->isHeld() || !self::isDoOrDelegate($message->getVerdict())) {
            return false;
        }
        $thread = $message->getThread();
        if ($thread->isClassifiedByUser() && !self::isDoOrDelegate($thread->getVerdict())) {
            return false;
        }

        return false !== $this->entityManager->getConnection()->fetchOne(
            "SELECT 1 FROM message_location l JOIN folder f ON f.id = l.folder_id WHERE l.message_id = :id AND f.role = 'inbox'",
            ['id' => $message->getId()->toRfc4122()],
        );
    }

    #[AsMessageHandler]
    public function onSend(SendMailNotification $message): void
    {
        $this->deliver($message->notificationId, new \DateTimeImmutable());
    }

    #[AsMessageHandler]
    public function onSweep(SweepMailNotifications $message): void
    {
        $this->sweep(new \DateTimeImmutable());
    }

    public function deliver(string $notificationId, \DateTimeImmutable $now): void
    {
        if (!Uuid::isValid($notificationId)) {
            return;
        }
        /** @var array{?MailNotification, list<array{string, ?NotificationDevice, ?string}>, ?\DateTimeImmutable} $next */
        $next = $this->entityManager->wrapInTransaction(function () use ($notificationId, $now): array {
            [$deliver, $retry, $waitUntil] = $this->prepare(Uuid::fromString($notificationId), $now);
            // Claimed in the transaction that marks it delivered: a crash before the sends leaves claimed rows, which
            // the sweep tries again, never a delivered notification nobody sends.
            $claimed = null === $deliver ? [] : $this->dispatcher->claim($deliver->getUser(), self::deliveryKey($deliver), NotificationChannelChoice::fromValues($deliver->getChannels()), !$retry);
            if (null !== $waitUntil) {
                $this->afterCommit->dispatch(new SendMailNotification($notificationId), [new DelayStamp(max(0, ($waitUntil->getTimestamp() - $now->getTimestamp()) * 1000))]);
            }

            return [$deliver, $claimed, $waitUntil];
        });
        [$deliver, $claimed] = $next;
        if (null !== $deliver && [] !== $claimed) {
            $this->dispatcher->deliver($claimed, $this->pushMessage($deliver, $now), self::deliveryKey($deliver), $now);
        }
        $this->entityManager->clear();
    }

    /**
     * Delivers due notifications whose message got lost, and sends failed or stuck deliveries again. Each on its own:
     * one that fails is logged and waits FIRE_BACKOFF, the others go on.
     */
    public function sweep(\DateTimeImmutable $now): void
    {
        $connection = $this->entityManager->getConnection();
        $latest = $now->modify('-'.self::MAX_LATE)->format('Y-m-d H:i:s');
        /** @var list<string> $due */
        $due = $connection->fetchFirstColumn(
            'SELECT id FROM mail_notification WHERE delivered_at IS NULL AND dropped_at IS NULL AND fire_at <= :now ORDER BY fire_at LIMIT :limit',
            ['now' => $now->format('Y-m-d H:i:s'), 'limit' => self::SWEEP_BATCH],
        );
        /** @var list<string> $retry */
        $retry = $connection->fetchFirstColumn(
            "SELECT DISTINCT n.id FROM mail_notification n
             JOIN notification_delivery d ON d.delivery_key = 'mail:' || n.id
             WHERE n.delivered_at > :latest
               AND ((d.status = :failed AND d.attempts < :max AND d.updated_at <= CAST(:now AS TIMESTAMP) - INTERVAL '1 minute' * POWER(2, d.attempts - 1))
                 OR (d.status = :sending AND d.updated_at <= CAST(:now AS TIMESTAMP) - CAST(:stuck AS INTERVAL)))
             LIMIT :limit",
            [
                'now' => $now->format('Y-m-d H:i:s'), 'latest' => $latest, 'limit' => self::SWEEP_BATCH,
                'failed' => NotificationDelivery::FAILED, 'sending' => NotificationDelivery::SENDING, 'max' => NotificationDispatcher::MAX_ATTEMPTS, 'stuck' => NotificationDispatcher::STUCK,
            ],
        );
        foreach (array_unique([...$due, ...$retry]) as $id) {
            try {
                $this->deliver($id, $now);
            } catch (\Throwable $e) {
                $this->logger->error('Delivering a mail notification failed', ['notification' => $id, 'error' => $e->getMessage()]);
                $this->managers->resetManager();
                $this->entityManager->getConnection()->executeStatement(
                    'UPDATE mail_notification SET fire_at = :later WHERE id = :id AND delivered_at IS NULL AND dropped_at IS NULL',
                    ['id' => $id, 'later' => $now->modify(self::FIRE_BACKOFF)->format('Y-m-d H:i:s')],
                );
            }
        }
    }

    public static function deliveryKey(MailNotification $notification): string
    {
        return 'mail:'.$notification->getId()->toRfc4122();
    }

    /**
     * What to do now: the notification to send (marked delivered), and whether that is a retry of an earlier delivery;
     * or the time it now waits until (a focus block or meeting); or nothing.
     *
     * @return array{?MailNotification, bool, ?\DateTimeImmutable}
     */
    private function prepare(Uuid $id, \DateTimeImmutable $now): array
    {
        $notification = $this->entityManager->find(MailNotification::class, $id, LockMode::PESSIMISTIC_WRITE);
        if (null === $notification || null !== $notification->getDroppedAt()) {
            return [null, false, null];
        }
        if ($notification->isDelivered()) {
            // Send again what failed or got stuck; the dispatcher leaves what went out alone.
            return [$notification->getDeliveredAt() > $now->modify('-'.self::MAX_LATE) ? $notification : null, true, null];
        }
        if ($notification->getFireAt()->getTimestamp() > $now->getTimestamp() + self::EARLY_SECONDS) {
            return [null, false, null];
        }
        $message = $notification->getMessage();
        if (null === $message || !$this->needsTheUser($message) || $notification->getFireAt() < $now->modify('-'.self::MAX_LATE)) {
            // Read, archived or judged otherwise meanwhile, or too late after an outage: no news any more.
            return $this->drop($notification, $now);
        }

        $user = $notification->getUser();
        $preferences = $this->preferences->forUser($user);
        $channels = $preferences->channelsFor(NotificationKind::Mail->value, NotificationKind::Mail->defaultChannels());
        if ($channels->isEmpty()) {
            return $this->drop($notification, $now);
        }
        $holdUntil = null;
        if ($preferences->isHoldDuringFocus()) {
            $holdUntil = $this->presence->focusBlockEnd($user, $now);
        }
        if (null === $holdUntil && $preferences->isHoldMailInMeetings()) {
            $holdUntil = $this->presence->meetingEnd($user, $now);
        }
        if (null !== $holdUntil && $holdUntil > $now) {
            $notification->postpone($holdUntil);
            $this->entityManager->flush();

            return [null, false, $holdUntil];
        }
        $silent = null !== QuietHours::endOf($preferences, $now)
            || ($preferences->isFocusDuringBusy() && $this->presence->inBusyEvent($user, $now));

        // Turned off since it was planned.
        if (!$preferences->isMailPreviews()) {
            $notification->hideContent(self::withoutContent($message));
        }
        $notification->markDelivered($now, $channels->values(), $silent);
        $this->entityManager->flush();

        return [$notification, false, null];
    }

    /**
     * @return array{null, false, null}
     */
    private function drop(MailNotification $notification, \DateTimeImmutable $now): array
    {
        $notification->drop($now);
        $this->entityManager->flush();

        return [null, false, null];
    }

    /** Mail with this Message-ID notified the user lately: the same mail reaching another of their accounts. */
    private function notifiedRecently(User $user, string $header, \DateTimeImmutable $now): bool
    {
        return false !== $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM mail_notification n JOIN message m ON m.id = n.message_id
             WHERE n.user_id = :user AND m.message_id_header = :header AND n.created_at > :since',
            ['user' => $user->getId()->toRfc4122(), 'header' => $header, 'since' => $now->modify('-'.self::FRESH)->format('Y-m-d H:i:s')],
        );
    }

    private function pushMessage(MailNotification $notification, \DateTimeImmutable $now): PushMessage
    {
        $threadId = $notification->getThread()?->getId()->toRfc4122();
        $data = array_filter(['threadId' => $threadId, 'messageId' => $notification->getMessage()?->getId()->toRfc4122()], static fn (?string $value): bool => null !== $value);

        return new PushMessage(
            $notification->getId()->toRfc4122(),
            NotificationKind::Mail->value,
            $notification->getTitle(),
            $notification->getBody(),
            null === $threadId ? null : '/thread/'.$threadId,
            $notification->isSilent(),
            $now->modify(self::EXPIRES),
            $data,
            PushMessage::MAIL,
        );
    }

    private static function isDoOrDelegate(?Verdict $verdict): bool
    {
        return Verdict::Do === $verdict || Verdict::Delegate === $verdict;
    }

    /** What a notification says without previews: only the account the mail came to, as the user named it. */
    private static function withoutContent(Message $message): string
    {
        $account = $message->getMailAccount();
        $name = trim($account->getDisplayName());

        return 'New mail from '.('' === $name ? $account->getEmailAddress() : $name);
    }

    /** Who it is from: their name, else their address. */
    private static function title(Message $message): string
    {
        $name = trim($message->getFromName() ?? '');

        return '' === $name ? $message->getFromAddress() : $name;
    }

    /** The subject (marked when it is to hand over), and the start of the text on the next line. */
    private static function body(Message $message): string
    {
        $subject = '' === trim($message->getSubject()) ? '(no subject)' : $message->getSubject();
        $line = Verdict::Delegate === $message->getVerdict() ? 'To hand over: '.$subject : $subject;
        $snippet = trim($message->getSnippet());

        return '' === $snippet ? $line : $line."\n".mb_substr($snippet, 0, 200);
    }
}
