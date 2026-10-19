<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\SyncChanges;
use App\Security\CurrentUser;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Reads the user's changes after a change number, in pages that never split one change number.
 *
 * @implements ProviderInterface<SyncChanges>
 */
final readonly class SyncChangesProvider implements ProviderInterface
{
    private const int DEFAULT_LIMIT = 500;

    public function __construct(
        private Connection $connection,
        private CurrentUser $currentUser,
        private RequestStack $requests,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SyncChanges
    {
        $userId = $this->currentUser->get()->getId()->toRfc4122();
        $query = $this->requests->getCurrentRequest()?->query;
        $since = (int) ($query?->get('since') ?? 0);
        $limit = (int) ($query?->get('limit') ?? self::DEFAULT_LIMIT);

        [$until, $hasMore] = $this->boundary($userId, $since, $limit);
        if ($until === $since) {
            return new SyncChanges($since, false, [], [], [], [], [], []);
        }

        $range = ['user' => $userId, 'since' => $since, 'until' => $until];

        return new SyncChanges(
            $until,
            $hasMore,
            $this->mailAccounts($range),
            $this->folders($range),
            $this->threads($range),
            $this->messages($range),
            $this->outgoingMessages($range),
            $this->deleted($range),
        );
    }

    /**
     * The highest change number such that the page holds at most $limit entities, but always at least one complete
     * change number.
     *
     * @return array{int, bool} [until, hasMore]
     */
    private function boundary(string $userId, int $since, int $limit): array
    {
        /** @var list<array{change_seq: int|string, rows: int|string}> $groups */
        $groups = $this->connection->fetchAllAssociative(
            'SELECT change_seq, SUM(rows) AS rows FROM (
                SELECT a.change_seq, COUNT(*) AS rows FROM mail_account a WHERE a.owner_id = :user AND a.change_seq > :since GROUP BY a.change_seq
                UNION ALL
                SELECT f.change_seq, COUNT(*) FROM folder f JOIN mail_account a ON a.id = f.mail_account_id WHERE a.owner_id = :user AND f.change_seq > :since GROUP BY f.change_seq
                UNION ALL
                SELECT t.change_seq, COUNT(*) FROM thread t JOIN mail_account a ON a.id = t.mail_account_id WHERE a.owner_id = :user AND t.change_seq > :since GROUP BY t.change_seq
                UNION ALL
                SELECT m.change_seq, COUNT(*) FROM message m JOIN mail_account a ON a.id = m.mail_account_id WHERE a.owner_id = :user AND m.change_seq > :since GROUP BY m.change_seq
                UNION ALL
                SELECT o.change_seq, COUNT(*) FROM outgoing_message o JOIN mail_account a ON a.id = o.mail_account_id WHERE a.owner_id = :user AND o.change_seq > :since GROUP BY o.change_seq
                UNION ALL
                SELECT s.change_seq, COUNT(*) FROM sync_tombstone s WHERE s.user_id = :user AND s.change_seq > :since GROUP BY s.change_seq
            ) changes
            GROUP BY change_seq
            ORDER BY change_seq
            LIMIT :groups',
            ['user' => $userId, 'since' => $since, 'groups' => $limit + 1],
        );

        // Every change number has at least one row, so $limit + 1 groups always reach past a full page.
        $until = $since;
        $total = 0;
        foreach ($groups as $group) {
            $rows = self::int($group['rows']);
            if ($total > 0 && $total + $rows > $limit) {
                return [$until, true];
            }
            $total += $rows;
            $until = self::int($group['change_seq']);
        }

        return [$until, false];
    }

    /**
     * @param array{user: string, since: int, until: int} $range
     *
     * @return list<array<string, mixed>>
     */
    private function mailAccounts(array $range): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT a.id, a.email_address, a.display_name, a.provider, a.status, a.status_detail, a.last_synced_at
             FROM mail_account a WHERE a.owner_id = :user AND a.change_seq > :since AND a.change_seq <= :until ORDER BY a.change_seq',
            $range,
        );

        return array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'emailAddress' => $r['email_address'],
            'displayName' => $r['display_name'],
            'provider' => $r['provider'],
            'status' => $r['status'],
            'statusDetail' => $r['status_detail'],
            'lastSyncedAt' => self::date($r['last_synced_at']),
        ], $rows);
    }

    /**
     * @param array{user: string, since: int, until: int} $range
     *
     * @return list<array<string, mixed>>
     */
    private function folders(array $range): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT f.id, f.mail_account_id, f.remote_name, f.display_name, f.role
             FROM folder f JOIN mail_account a ON a.id = f.mail_account_id
             WHERE a.owner_id = :user AND f.change_seq > :since AND f.change_seq <= :until ORDER BY f.change_seq',
            $range,
        );

        return array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'mailAccountId' => $r['mail_account_id'],
            'name' => $r['remote_name'],
            'displayName' => $r['display_name'],
            'role' => $r['role'],
        ], $rows);
    }

    /**
     * @param array{user: string, since: int, until: int} $range
     *
     * @return list<array<string, mixed>>
     */
    private function threads(array $range): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT t.id, t.mail_account_id, t.subject, t.message_count, t.last_message_at
             FROM thread t JOIN mail_account a ON a.id = t.mail_account_id
             WHERE a.owner_id = :user AND t.change_seq > :since AND t.change_seq <= :until ORDER BY t.change_seq',
            $range,
        );

        return array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'mailAccountId' => $r['mail_account_id'],
            'subject' => $r['subject'],
            'messageCount' => self::int($r['message_count']),
            'lastMessageAt' => self::date($r['last_message_at']),
        ], $rows);
    }

    /**
     * @param array{user: string, since: int, until: int} $range
     *
     * @return list<array<string, mixed>>
     */
    private function messages(array $range): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT m.id, m.mail_account_id, m.thread_id, m.subject, m.from_address, m.from_name, m.recipients, m.snippet,
                    m.sent_at, m.received_at, m.seen, m.flagged, m.answered, m.draft, m.has_attachments,
                    m.category_id, m.verdict, m.summary,
                    COALESCE((SELECT json_agg(l.folder_id ORDER BY l.folder_id) FROM message_location l WHERE l.message_id = m.id), '[]') AS folder_ids
             FROM message m JOIN mail_account a ON a.id = m.mail_account_id
             WHERE a.owner_id = :user AND m.change_seq > :since AND m.change_seq <= :until ORDER BY m.change_seq",
            $range,
        );

        return array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'mailAccountId' => $r['mail_account_id'],
            'threadId' => $r['thread_id'],
            'folderIds' => json_decode(\is_string($r['folder_ids']) ? $r['folder_ids'] : '[]', true, flags: \JSON_THROW_ON_ERROR),
            'subject' => $r['subject'],
            'from' => ['address' => $r['from_address'], 'name' => $r['from_name']],
            'recipients' => json_decode(\is_string($r['recipients']) ? $r['recipients'] : '{}', true, flags: \JSON_THROW_ON_ERROR),
            'snippet' => $r['snippet'],
            'sentAt' => self::date($r['sent_at']),
            'receivedAt' => self::date($r['received_at']),
            'flags' => ['seen' => (bool) $r['seen'], 'flagged' => (bool) $r['flagged'], 'answered' => (bool) $r['answered'], 'draft' => (bool) $r['draft']],
            'hasAttachments' => (bool) $r['has_attachments'],
            'categoryId' => $r['category_id'],
            'verdict' => $r['verdict'],
            'summary' => $r['summary'],
        ], $rows);
    }

    /**
     * Bcc recipients are included: only the sender's own clients see this.
     *
     * @param array{user: string, since: int, until: int} $range
     *
     * @return list<array<string, mixed>>
     */
    private function outgoingMessages(array $range): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT o.id, o.mail_account_id, o.status, o.subject, o.recipients, o.send_at, o.sent_at, o.last_error, o.in_reply_to_id
             FROM outgoing_message o JOIN mail_account a ON a.id = o.mail_account_id
             WHERE a.owner_id = :user AND o.change_seq > :since AND o.change_seq <= :until ORDER BY o.change_seq',
            $range,
        );

        return array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'mailAccountId' => $r['mail_account_id'],
            'status' => $r['status'],
            'subject' => $r['subject'],
            'recipients' => json_decode(\is_string($r['recipients']) ? $r['recipients'] : '{}', true, flags: \JSON_THROW_ON_ERROR),
            'sendAt' => self::date($r['send_at']),
            'sentAt' => self::date($r['sent_at']),
            'lastError' => $r['last_error'],
            'inReplyToMessageId' => $r['in_reply_to_id'],
        ], $rows);
    }

    /**
     * @param array{user: string, since: int, until: int} $range
     *
     * @return list<array{type: string, id: string}>
     */
    private function deleted(array $range): array
    {
        /** @var list<array{entity_type: string, entity_id: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.entity_type, s.entity_id FROM sync_tombstone s
             WHERE s.user_id = :user AND s.change_seq > :since AND s.change_seq <= :until ORDER BY s.change_seq',
            $range,
        );

        return array_map(static fn (array $r): array => ['type' => $r['entity_type'], 'id' => $r['entity_id']], $rows);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new \UnexpectedValueException('Expected a number from the database.');
    }

    private static function date(mixed $value): ?string
    {
        return \is_string($value) ? new \DateTimeImmutable($value)->format(\DATE_ATOM) : null;
    }
}
