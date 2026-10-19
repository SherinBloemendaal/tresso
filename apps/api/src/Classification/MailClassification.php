<?php

declare(strict_types=1);

namespace App\Classification;

use App\Entity\Category;
use App\Entity\Message;
use App\Entity\Thread;
use App\Entity\User;
use App\Enum\FolderRole;
use App\Enum\Verdict;
use App\Repository\CategoryRepository;
use App\Repository\MailAccountRepository;
use App\Repository\SenderRuleRepository;
use App\Sync\Change\ChangeSequencer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Runs the {@see Classifier} over a user's mail and stores the outcome: on each incoming message (clients notify on
 * its verdict) and on its conversation, which takes the smart inbox and verdict of its newest incoming message. The
 * user's own messages are never classified. Conversations the user put in a smart inbox themselves keep it.
 *
 * Work goes in batches, each in its own transaction, so change numbers commit in order and a crash loses at most one
 * batch, which the next run picks up again. Runs for one user take turns on an advisory lock, so two runs never
 * classify the same message at once.
 */
final readonly class MailClassification
{
    private const int BATCH_SIZE = 200;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private Classifier $classifier,
        private BuiltinSmartInboxes $builtinSmartInboxes,
        private CategoryRepository $smartInboxes,
        private SenderRuleRepository $senderRules,
        private MailAccountRepository $accounts,
        private ChangeSequencer $sequencer,
    ) {
    }

    /** Classifies every message of the user that was not looked at yet. */
    public function classifyNew(Uuid $userId): void
    {
        do {
            $count = $this->entityManager->wrapInTransaction(fn (): int => $this->classifyNewBatch($userId));
            $this->entityManager->clear();
        } while (self::BATCH_SIZE === $count);
    }

    /**
     * Classifies the user's conversations again: those with mail since $since (every one when null), and those that
     * lost their smart inbox. Conversations the user placed themselves are left alone.
     */
    public function reclassify(Uuid $userId, ?\DateTimeImmutable $since): void
    {
        $after = '00000000-0000-0000-0000-000000000000';
        do {
            $threadIds = $this->entityManager->wrapInTransaction(fn (): array => $this->reclassifyBatch($userId, $since, $after));
            $this->entityManager->clear();
            $after = end($threadIds) ?: $after;
        } while (self::BATCH_SIZE === \count($threadIds));
    }

    private function classifyNewBatch(Uuid $userId): int
    {
        $this->lock($userId);
        $user = $this->entityManager->find(User::class, $userId);
        if (null === $user) {
            return 0;
        }

        /** @var list<string> $messageIds */
        $messageIds = $this->connection->fetchFirstColumn(
            'SELECT m.id FROM message m JOIN mail_account a ON a.id = m.mail_account_id
             WHERE a.owner_id = :user AND m.classified_at IS NULL
             ORDER BY m.received_at, m.id LIMIT :limit',
            ['user' => $userId->toRfc4122(), 'limit' => self::BATCH_SIZE],
        );
        if ([] === $messageIds) {
            return 0;
        }

        $context = $this->context($user);
        $now = new \DateTimeImmutable();
        $messages = $this->incomingMessages($messageIds, $context);

        /** @var array<string, Classification> $byMessage */
        $byMessage = [];
        foreach ($this->mailToClassify($messages) as $id => $mail) {
            $classification = $this->classifier->classify($mail, $context);
            $messages[$id]->classify($classification->smartInbox, $classification->verdict, $now);
            $byMessage[$id] = $classification;
        }
        $this->entityManager->flush();

        // A conversation follows its newest incoming message, if that is one classified just now.
        $threads = [];
        foreach ($messages as $message) {
            $threads[$message->getThread()->getId()->toRfc4122()] = $message->getThread();
        }
        $newest = $this->newestIncoming(array_keys($threads), $context);
        foreach ($threads as $threadId => $thread) {
            $messageId = $newest[$threadId] ?? null;
            if (null !== $messageId && isset($byMessage[$messageId]) && !$thread->isClassifiedByUser()) {
                $classification = $byMessage[$messageId];
                $thread->classify($classification->smartInbox, $classification->verdict, $classification->reason, $now);
            }
        }
        $this->entityManager->flush();

        // The user's own messages are only marked as looked at; not a change clients see.
        $this->connection->executeStatement(
            'UPDATE message SET classified_at = :now WHERE id = ANY(CAST(:ids AS UUID[])) AND classified_at IS NULL',
            ['now' => $now->format('Y-m-d H:i:s'), 'ids' => '{'.implode(',', $messageIds).'}'],
        );

        return \count($messageIds);
    }

    /**
     * @return list<string> the thread ids of the batch, in id order
     */
    private function reclassifyBatch(Uuid $userId, ?\DateTimeImmutable $since, string $after): array
    {
        $this->lock($userId);
        $user = $this->entityManager->find(User::class, $userId);
        if (null === $user) {
            return [];
        }

        /** @var list<string> $threadIds */
        $threadIds = $this->connection->fetchFirstColumn(
            "SELECT t.id FROM thread t JOIN mail_account a ON a.id = t.mail_account_id
             WHERE a.owner_id = :user AND t.id > :after
               AND (t.classification_reason IS NULL OR t.classification_reason <> :byUser)
               AND (CAST(:since AS TIMESTAMP) IS NULL OR t.last_message_at >= CAST(:since AS TIMESTAMP)
                    OR (t.category_id IS NULL AND t.classified_at IS NOT NULL))
             ORDER BY t.id LIMIT :limit",
            [
                'user' => $userId->toRfc4122(),
                'after' => $after,
                'byUser' => ClassificationReason::USER,
                'since' => $since?->format('Y-m-d H:i:s'),
                'limit' => self::BATCH_SIZE,
            ],
        );
        if ([] === $threadIds) {
            return [];
        }

        $context = $this->context($user);
        $now = new \DateTimeImmutable();
        $newest = $this->newestIncoming($threadIds, $context);
        $messages = $this->incomingMessages(array_values($newest), $context);
        foreach ($this->mailToClassify($messages) as $id => $mail) {
            $classification = $this->classifier->classify($mail, $context);
            $message = $messages[$id];
            $message->classify($classification->smartInbox, $classification->verdict, $now);
            $message->getThread()->classify($classification->smartInbox, $classification->verdict, $classification->reason, $now);
        }
        $this->entityManager->flush();

        return $threadIds;
    }

    /**
     * Applies the user's choice to a conversation: it moves to the smart inbox with the verdict, now and for good, and
     * so does its newest incoming message. Returns that message, whose sender the choice is remembered for, or null
     * when the conversation has only the user's own messages.
     */
    public function placeByUser(Thread $thread, Category $smartInbox, Verdict $verdict): ?Message
    {
        $user = $thread->getMailAccount()->getOwner();
        $context = new ClassificationContext($this->ownAddresses($user), [], []);
        $now = new \DateTimeImmutable();

        $thread->classify($smartInbox, $verdict, ClassificationReason::USER, $now);
        $messageId = $this->newestIncoming([$thread->getId()->toRfc4122()], $context)[$thread->getId()->toRfc4122()] ?? null;
        $message = null === $messageId ? null : $this->entityManager->find(Message::class, Uuid::fromString($messageId));
        $message?->classify($smartInbox, $verdict, $now);

        return $message;
    }

    private function lock(Uuid $userId): void
    {
        $this->connection->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))',
            ['key' => 'classify:'.$userId->toRfc4122()],
        );
    }

    private function context(User $user): ClassificationContext
    {
        if ([] !== $this->builtinSmartInboxes->ensure($user)) {
            $this->entityManager->flush();
        }
        $this->syncOlderSmartInboxes($user);

        return new ClassificationContext($this->ownAddresses($user), $this->smartInboxes->findOwnedBy($user), $this->senderRules->findOwnedBy($user));
    }

    /**
     * Smart inboxes created before clients synced them have no change number, so clients would never get them. They
     * get one here, the first time the user's mail is classified.
     */
    private function syncOlderSmartInboxes(User $user): void
    {
        $params = ['user' => $user->getId()->toRfc4122()];
        if (false === $this->connection->fetchOne('SELECT 1 FROM category WHERE owner_id = :user AND change_seq = 0 LIMIT 1', $params)) {
            return;
        }
        $this->connection->executeStatement(
            'UPDATE category SET change_seq = :seq WHERE owner_id = :user AND change_seq = 0',
            [...$params, 'seq' => $this->sequencer->next($user->getId())],
        );
    }

    /** @return list<string> */
    private function ownAddresses(User $user): array
    {
        $addresses = [];
        foreach ($this->accounts->findOwnedBy($user) as $account) {
            $addresses[] = mb_strtolower($account->getEmailAddress());
        }

        return $addresses;
    }

    /**
     * The newest message of each thread that someone else sent.
     *
     * @param list<string> $threadIds
     *
     * @return array<string, string> thread id to message id
     */
    private function newestIncoming(array $threadIds, ClassificationContext $context): array
    {
        if ([] === $threadIds) {
            return [];
        }

        /** @var list<array{thread_id: string, id: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT ON (m.thread_id) m.thread_id, m.id FROM message m
             WHERE m.thread_id = ANY(CAST(:threads AS UUID[])) AND NOT (m.from_address = ANY(CAST(:own AS TEXT[])))
             ORDER BY m.thread_id, m.received_at DESC, m.id DESC',
            ['threads' => '{'.implode(',', $threadIds).'}', 'own' => self::textArray($context->ownAddresses())],
        );

        return array_column($rows, 'id', 'thread_id');
    }

    /**
     * The given messages that someone else sent, loaded as entities.
     *
     * @param list<string> $messageIds
     *
     * @return array<string, Message> by id
     */
    private function incomingMessages(array $messageIds, ClassificationContext $context): array
    {
        if ([] === $messageIds) {
            return [];
        }

        /** @var list<Message> $messages */
        $messages = $this->entityManager->getRepository(Message::class)->findBy(['id' => array_map(Uuid::fromString(...), $messageIds)]);
        $incoming = [];
        foreach ($messages as $message) {
            if (!$context->isOwnAddress($message->getFromAddress())) {
                $incoming[$message->getId()->toRfc4122()] = $message;
            }
        }

        return $incoming;
    }

    /**
     * @param array<string, Message> $messages
     *
     * @return array<string, MailToClassify> by message id
     */
    private function mailToClassify(array $messages): array
    {
        if ([] === $messages) {
            return [];
        }

        /** @var list<array{id: string, headers: ?string, junk: bool|null, roles: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT m.id, s.headers, s.junk,
                    COALESCE((SELECT json_agg(f.role) FROM message_location l JOIN folder f ON f.id = l.folder_id WHERE l.message_id = m.id), '[]') AS roles
             FROM message m LEFT JOIN message_signals s ON s.message_id = m.id
             WHERE m.id = ANY(CAST(:ids AS UUID[]))",
            ['ids' => '{'.implode(',', array_keys($messages)).'}'],
        );
        $extra = array_column($rows, null, 'id');

        $result = [];
        foreach ($messages as $id => $message) {
            $row = $extra[$id] ?? ['headers' => null, 'junk' => false, 'roles' => '[]'];
            /** @var array<string, string> $headers */
            $headers = null === $row['headers'] ? [] : json_decode($row['headers'], true, flags: \JSON_THROW_ON_ERROR);
            /** @var list<string> $roles */
            $roles = json_decode($row['roles'], true, flags: \JSON_THROW_ON_ERROR);
            $recipients = $message->getRecipients();

            $result[$id] = new MailToClassify(
                $id,
                $message->getFromAddress(),
                $message->getFromName(),
                array_map(static fn (array $a): string => mb_strtolower($a['address']), $recipients['to'] ?? []),
                array_map(static fn (array $a): string => mb_strtolower($a['address']), $recipients['cc'] ?? []),
                $message->getSubject(),
                $message->getSnippet(),
                $headers,
                (bool) $row['junk'],
                array_values(array_filter(array_map(FolderRole::tryFrom(...), $roles))),
            );
        }

        return $result;
    }

    /** @param list<string> $values */
    private static function textArray(array $values): string
    {
        return '{'.implode(',', array_map(static fn (string $v): string => '"'.addcslashes($v, '"\\').'"', $values)).'}';
    }
}
