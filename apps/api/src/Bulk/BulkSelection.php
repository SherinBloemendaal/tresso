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
    /** How many conversations one batch looks at, at most. */
    public const int SCAN_PER_BATCH = 1000;

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
     * The next conversations of the scope in id order, at most $maxConversations and no more than about $maxMessages
     * messages, but at least one conversation however long. Each call looks at a window of at most $scan
     * conversations through an index in id order ((category_id, id) for a smart inbox, (mail_account_id, id) for the
     * owner's accounts one after the other) and tests the scope only on those, so a batch costs the same at the end
     * of a big mailbox as at the start, and the whole run reads each conversation once.
     */
    public function nextBatch(User $owner, BulkScope $scope, Uuid $boundary, ?Uuid $account, ?Uuid $after, int $maxConversations, int $maxMessages, int $scan = self::SCAN_PER_BATCH): BulkBatch
    {
        [$filters, $parameters] = $this->filters($owner, $scope);
        // The window is read through the index alone; whether a conversation is the owner's is part of the test.
        $matches = implode(' AND ', ['t.mail_account_id IN (SELECT oa.id FROM mail_account oa WHERE oa.owner_id = :owner)', ...$filters]);
        $accounts = BulkArea::SmartInbox === $scope->area ? [null] : $this->accountIds($owner, $scope);
        $index = null === $account ? 0 : array_search($account->toRfc4122(), $accounts, true);
        if (false === $index) {
            // The account was removed meanwhile, and its mail with it: go on with the next one.
            $removed = $account->toRfc4122();
            $index = \count(array_filter($accounts, static fn (?string $id): bool => null !== $id && $id < $removed));
            $after = null;
        }

        $threadIds = [];
        $messages = 0;
        for (; $index < \count($accounts); ++$index, $after = null) {
            $driver = null === $accounts[$index] ? 't.category_id = :smartInbox' : 't.mail_account_id = :driver';
            /** @var list<array{id: string, message_count: int, matches: bool}> $rows */
            $rows = $this->connection->fetchAllAssociative(
                "SELECT t.id, t.message_count, ({$matches}) AS matches
                 FROM thread t
                 WHERE {$driver} AND t.id > :after AND t.id < :boundary
                 ORDER BY t.id LIMIT :scan",
                [
                    ...$parameters,
                    ...(null === $accounts[$index] ? [] : ['driver' => $accounts[$index]]),
                    'boundary' => $boundary->toRfc4122(),
                    'after' => ($after ?? Uuid::fromString('00000000-0000-0000-0000-000000000000'))->toRfc4122(),
                    'scan' => $scan,
                ],
            );
            foreach ($rows as $row) {
                if ($row['matches']) {
                    $full = [] !== $threadIds && ($messages + (int) $row['message_count'] > $maxMessages || \count($threadIds) >= $maxConversations);
                    if ($full) {
                        return new BulkBatch($threadIds, self::accountUuid($accounts[$index]), $after, false);
                    }
                    $threadIds[] = $row['id'];
                    $messages += (int) $row['message_count'];
                }
                $after = Uuid::fromString($row['id']);
            }
            if (\count($rows) === $scan) {
                return new BulkBatch($threadIds, self::accountUuid($accounts[$index]), $after, false);
            }
        }

        return new BulkBatch($threadIds, null, null, true);
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
        [$filters, $parameters] = $this->filters($owner, $scope);

        return [
            implode(' AND ', ['a.owner_id = :owner', 't.id < :boundary', ...$filters]),
            [...$parameters, 'boundary' => $boundary->toRfc4122()],
        ];
    }

    /**
     * What makes a conversation of the owner part of the scope. Sender and size are matched through the message
     * indexes on (mail_account_id, from_address) and (mail_account_id, size), over the owner's accounts.
     *
     * @return array{list<string>, array<string, string|int>}
     */
    private function filters(User $owner, BulkScope $scope): array
    {
        $conditions = [];
        $parameters = ['owner' => $owner->getId()->toRfc4122()];

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
            $conditions[] = 't.id IN (SELECT sm.thread_id FROM message sm JOIN mail_account sa ON sa.id = sm.mail_account_id WHERE sa.owner_id = :owner AND sm.from_address = :sender)';
            $parameters['sender'] = $scope->sender;
        }
        if (null !== $scope->before) {
            $conditions[] = 't.last_message_at < :before';
            $parameters['before'] = $scope->before->format('Y-m-d H:i:s');
        }
        if (null !== $scope->largerThan) {
            $conditions[] = 't.id IN (SELECT lm.thread_id FROM message lm JOIN mail_account la ON la.id = lm.mail_account_id WHERE la.owner_id = :owner AND lm.size >= :largerThan)';
            $parameters['largerThan'] = $scope->largerThan;
        }

        return [$conditions, $parameters];
    }

    /**
     * The accounts whose conversations the scope takes, in id order.
     *
     * @return list<string>
     */
    private function accountIds(User $owner, BulkScope $scope): array
    {
        if (null !== $scope->mailAccount) {
            return [$scope->mailAccount->getId()->toRfc4122()];
        }

        /** @var list<string> */
        return $this->connection->fetchFirstColumn('SELECT id FROM mail_account WHERE owner_id = :owner ORDER BY id', ['owner' => $owner->getId()->toRfc4122()]);
    }

    private static function accountUuid(?string $id): ?Uuid
    {
        return null === $id ? null : Uuid::fromString($id);
    }

    private static function inFolder(string $roles): string
    {
        return "EXISTS (SELECT 1 FROM message fm JOIN message_location fl ON fl.message_id = fm.id JOIN folder ff ON ff.id = fl.folder_id
                        WHERE fm.thread_id = t.id AND ff.role {$roles})";
    }
}
