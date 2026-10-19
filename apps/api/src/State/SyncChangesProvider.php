<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\SyncChanges;
use App\Classification\BuiltinSmartInboxes;
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
        private BuiltinSmartInboxes $builtinSmartInboxes,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SyncChanges
    {
        $user = $this->currentUser->get();
        $userId = $user->getId()->toRfc4122();
        $query = $this->requests->getCurrentRequest()?->query;
        $since = (int) ($query?->get('since') ?? 0);
        $limit = (int) ($query?->get('limit') ?? self::DEFAULT_LIMIT);

        // A sync from the start (a new device, or a copy rebuilt) also brings smart inboxes made before they synced.
        if (0 === $since) {
            $this->connection->transactional(fn () => $this->builtinSmartInboxes->syncOlder($user));
        }

        [$until, $hasMore] = $this->boundary($userId, $since, $limit);
        if ($until === $since) {
            return new SyncChanges($since, false, [], [], [], [], [], [], [], [], []);
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
            $this->signatures($range),
            $this->drafts($range),
            $this->smartInboxes($range),
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
                SELECT g.change_seq, COUNT(*) FROM signature g WHERE g.owner_id = :user AND g.change_seq > :since GROUP BY g.change_seq
                UNION ALL
                SELECT d.change_seq, COUNT(*) FROM draft d WHERE d.owner_id = :user AND d.change_seq > :since AND d.sending_as_id IS NULL GROUP BY d.change_seq
                UNION ALL
                SELECT c.change_seq, COUNT(*) FROM category c WHERE c.owner_id = :user AND c.change_seq > :since GROUP BY c.change_seq
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
            'SELECT a.id, a.email_address, a.display_name, a.provider, a.status, a.status_detail, a.last_synced_at, a.default_signature_id
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
            'defaultSignatureId' => $r['default_signature_id'],
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
            'SELECT t.id, t.mail_account_id, t.subject, t.message_count, t.last_message_at, t.category_id, t.verdict, t.classification_reason
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
            'smartInboxId' => $r['category_id'],
            'verdict' => $r['verdict'],
            'classificationReason' => $r['classification_reason'],
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
            'smartInboxId' => $r['category_id'],
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
     * @return list<array<string, mixed>>
     */
    private function signatures(array $range): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT g.id, g.name, g.html, g.updated_at
             FROM signature g WHERE g.owner_id = :user AND g.change_seq > :since AND g.change_seq <= :until ORDER BY g.change_seq',
            $range,
        );

        return array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'name' => $r['name'],
            'html' => $r['html'],
            'updatedAt' => self::date($r['updated_at']),
        ], $rows);
    }

    /**
     * @param array{user: string, since: int, until: int} $range
     *
     * @return list<array<string, mixed>>
     */
    private function drafts(array $range): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT d.id, d.mail_account_id, d.kind, d.recipients, d.subject, d.html, d.signature_id, d.quote_html, d.in_reply_to_id, d.version, d.updated_at
             FROM draft d WHERE d.owner_id = :user AND d.change_seq > :since AND d.change_seq <= :until AND d.sending_as_id IS NULL ORDER BY d.change_seq',
            $range,
        );
        // The files without links: links expire, and the copy on a device lasts. GET /drafts/{id} has fresh ones.
        $attachments = [];
        if ([] !== $rows) {
            foreach ($this->connection->fetchAllAssociative(
                'SELECT a.draft_id, a.id, a.filename, a.content_type, a.size, a.scan_status, a.inline, a.content_id FROM draft_attachment a
                 WHERE a.draft_id = ANY(CAST(:drafts AS UUID[])) ORDER BY a.draft_id, a.position',
                ['drafts' => '{'.implode(',', array_map(static fn (array $r): string => self::text($r['id']), $rows)).'}'],
            ) as $a) {
                $attachments[self::text($a['draft_id'])][] = [
                    'id' => $a['id'],
                    'filename' => $a['filename'],
                    'contentType' => $a['content_type'],
                    'size' => self::int($a['size']),
                    'scanStatus' => $a['scan_status'],
                    'inline' => (bool) $a['inline'],
                    'contentId' => $a['content_id'],
                ];
            }
        }

        return array_map(static function (array $r) use ($attachments): array {
            /** @var array{to?: list<array{address: string, name: ?string}>, cc?: list<array{address: string, name: ?string}>, bcc?: list<array{address: string, name: ?string}>} $recipients */
            $recipients = json_decode(\is_string($r['recipients']) ? $r['recipients'] : '{}', true, flags: \JSON_THROW_ON_ERROR);

            return [
                'id' => $r['id'],
                'mailAccountId' => $r['mail_account_id'],
                'kind' => $r['kind'],
                'to' => $recipients['to'] ?? [],
                'cc' => $recipients['cc'] ?? [],
                'bcc' => $recipients['bcc'] ?? [],
                'subject' => $r['subject'],
                'html' => $r['html'],
                'signatureId' => $r['signature_id'],
                'quoteHtml' => $r['quote_html'],
                'inReplyToMessageId' => $r['in_reply_to_id'],
                'version' => self::int($r['version']),
                'updatedAt' => self::date($r['updated_at']),
                'attachments' => $attachments[self::text($r['id'])] ?? [],
            ];
        }, $rows);
    }

    /**
     * @param array{user: string, since: int, until: int} $range
     *
     * @return list<array<string, mixed>>
     */
    private function smartInboxes(array $range): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.builtin, c.name, c.instructions, c.include_senders, c.exclude_senders, c.verdict, c.position
             FROM category c WHERE c.owner_id = :user AND c.change_seq > :since AND c.change_seq <= :until ORDER BY c.change_seq',
            $range,
        );

        return array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'builtin' => $r['builtin'],
            'name' => $r['name'],
            'instructions' => $r['instructions'],
            'includeSenders' => json_decode(\is_string($r['include_senders']) ? $r['include_senders'] : '[]', true, flags: \JSON_THROW_ON_ERROR),
            'excludeSenders' => json_decode(\is_string($r['exclude_senders']) ? $r['exclude_senders'] : '[]', true, flags: \JSON_THROW_ON_ERROR),
            'verdict' => $r['verdict'],
            'position' => self::int($r['position']),
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

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('Expected a text column.');
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
