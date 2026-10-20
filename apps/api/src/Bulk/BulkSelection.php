<?php

declare(strict_types=1);

namespace App\Bulk;

use App\Entity\User;
use App\Enum\BuiltinCategory;
use App\Enum\BulkArea;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Finds the conversations of a {@see BulkScope} in SQL, always within the owner's own accounts. Lists match the
 * clients' (`inList` in packages/core): a smart inbox lists its conversations with a message in an inbox or junk
 * folder, the inboxes list conversations with a message in an inbox folder unless they are in the Spam smart inbox.
 *
 * Only conversations and messages that reached Tresso before $boundary count: ids are UUIDv7, ordered by creation.
 */
final readonly class BulkSelection
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{conversations: int, messages: int, mailAccountIds: list<string>}
     */
    public function count(User $owner, BulkScope $scope, Uuid $boundary): array
    {
        [$where, $parameters] = $this->where($owner, $scope, $boundary);
        /** @var list<array{mail_account_id: string, conversations: int, messages: int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT t.mail_account_id, COUNT(*) AS conversations, SUM(t.message_count) AS messages
             FROM thread t JOIN mail_account a ON a.id = t.mail_account_id
             WHERE {$where} GROUP BY t.mail_account_id ORDER BY t.mail_account_id",
            $parameters,
        );

        return [
            'conversations' => array_sum(array_map(static fn (array $row): int => (int) $row['conversations'], $rows)),
            'messages' => array_sum(array_map(static fn (array $row): int => (int) $row['messages'], $rows)),
            'mailAccountIds' => array_map(static fn (array $row): string => $row['mail_account_id'], $rows),
        ];
    }

    /**
     * The next conversations in id order after $after: at most $maxConversations, and no more than about
     * $maxMessages messages, but always at least one conversation, however long.
     *
     * @return list<string> thread ids
     */
    public function nextBatch(User $owner, BulkScope $scope, Uuid $boundary, ?Uuid $after, int $maxConversations, int $maxMessages): array
    {
        [$where, $parameters] = $this->where($owner, $scope, $boundary);

        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            "SELECT id FROM (
                 SELECT id, message_count, SUM(message_count) OVER (ORDER BY id) AS running FROM (
                     SELECT t.id, t.message_count FROM thread t JOIN mail_account a ON a.id = t.mail_account_id
                     WHERE {$where} AND t.id > :after ORDER BY t.id LIMIT :conversations
                 ) page
             ) batch
             WHERE running - message_count < :messages ORDER BY id",
            [
                ...$parameters,
                'after' => ($after ?? Uuid::fromString('00000000-0000-0000-0000-000000000000'))->toRfc4122(),
                'conversations' => $maxConversations,
                'messages' => $maxMessages,
            ],
        );
    }

    /**
     * The messages of these conversations that reached Tresso before $boundary.
     *
     * @param list<string> $threadIds
     *
     * @return list<string>
     */
    public function messageIds(array $threadIds, Uuid $boundary): array
    {
        if ([] === $threadIds) {
            return [];
        }

        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT id FROM message WHERE thread_id = ANY(CAST(:threads AS UUID[])) AND id < :boundary ORDER BY id',
            ['threads' => '{'.implode(',', $threadIds).'}', 'boundary' => $boundary->toRfc4122()],
        );
    }

    /**
     * The conditions on `thread t` joined with `mail_account a`.
     *
     * @return array{string, array<string, string|int>}
     */
    public function where(User $owner, BulkScope $scope, Uuid $boundary): array
    {
        $conditions = ['a.owner_id = :owner', 't.id < :boundary'];
        $parameters = ['owner' => $owner->getId()->toRfc4122(), 'boundary' => $boundary->toRfc4122()];

        switch ($scope->area) {
            case BulkArea::SmartInbox:
                $conditions[] = 't.category_id = :smartInbox';
                $conditions[] = self::inFolder("IN ('inbox', 'spam')");
                $parameters['smartInbox'] = ($scope->smartInbox ?? throw new \LogicException('A smart inbox scope has a smart inbox.'))->getId()->toRfc4122();
                break;
            case BulkArea::Inbox:
                $conditions[] = self::inFolder("= 'inbox'");
                $conditions[] = 'NOT EXISTS (SELECT 1 FROM category c WHERE c.id = t.category_id AND c.builtin = :spam)';
                $parameters['spam'] = BuiltinCategory::Spam->value;
                break;
            case BulkArea::Mailbox:
                $conditions[] = self::inFolder("NOT IN ('trash', 'spam', 'drafts')");
                break;
        }
        if (null !== $scope->mailAccount) {
            $conditions[] = 't.mail_account_id = :account';
            $parameters['account'] = $scope->mailAccount->getId()->toRfc4122();
        }
        if (null !== $scope->sender) {
            $conditions[] = 'EXISTS (SELECT 1 FROM message sm WHERE sm.thread_id = t.id AND sm.mail_account_id = t.mail_account_id AND sm.from_address = :sender)';
            $parameters['sender'] = $scope->sender;
        }
        if (null !== $scope->before) {
            $conditions[] = 't.last_message_at < :before';
            $parameters['before'] = $scope->before->format('Y-m-d H:i:s');
        }
        if (null !== $scope->largerThan) {
            $conditions[] = 'EXISTS (SELECT 1 FROM message lm WHERE lm.thread_id = t.id AND lm.mail_account_id = t.mail_account_id AND lm.size >= :largerThan)';
            $parameters['largerThan'] = $scope->largerThan;
        }

        return [implode(' AND ', $conditions), $parameters];
    }

    private static function inFolder(string $roles): string
    {
        return "EXISTS (SELECT 1 FROM message fm JOIN message_location fl ON fl.message_id = fm.id JOIN folder ff ON ff.id = fl.folder_id
                        WHERE fm.thread_id = t.id AND ff.role {$roles})";
    }
}
