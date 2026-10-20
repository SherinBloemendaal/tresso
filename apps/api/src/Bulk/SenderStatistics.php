<?php

declare(strict_types=1);

namespace App\Bulk;

use App\Entity\Category;
use App\Entity\User;
use App\Enum\BulkArea;
use App\Repository\MailAccountRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Who sends the conversations of a smart inbox, and how much of it the user reads: the numbers the smart inbox's
 * sender list shows next to its bulk actions. Counts only what the smart inbox lists, within the owner's accounts,
 * and leaves out the user's own addresses (their replies are not a sender to clean up).
 */
final readonly class SenderStatistics
{
    public const int MAX_SENDERS = 100;

    public function __construct(
        private Connection $connection,
        private BulkSelection $selection,
        private MailAccountRepository $accounts,
    ) {
    }

    /**
     * @return array{
     *     conversations: int,
     *     messages: int,
     *     unread: int,
     *     senders: list<array{address: string, name: ?string, conversations: int, messages: int, unread: int, lastReceivedAt: \DateTimeImmutable}>
     * }
     */
    public function forSmartInbox(User $owner, Category $smartInbox): array
    {
        [$where, $parameters] = $this->selection->where($owner, new BulkScope(BulkArea::SmartInbox, $smartInbox), Uuid::v7());
        $listed = "SELECT t.id FROM thread t JOIN mail_account a ON a.id = t.mail_account_id WHERE {$where}";

        /** @var array{conversations: int, messages: int|null, unread: int|null} $totals */
        $totals = $this->connection->fetchAssociative(
            "SELECT COUNT(DISTINCT m.thread_id) AS conversations, COUNT(m.id) AS messages, COUNT(m.id) FILTER (WHERE NOT m.seen) AS unread
             FROM ({$listed}) listed JOIN message m ON m.thread_id = listed.id",
            $parameters,
        );

        /** @var list<array{address: string, name: ?string, conversations: int, messages: int, unread: int, last_received_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT m.from_address AS address,
                    (ARRAY_AGG(m.from_name ORDER BY m.received_at DESC) FILTER (WHERE m.from_name IS NOT NULL AND m.from_name <> ''))[1] AS name,
                    COUNT(DISTINCT m.thread_id) AS conversations, COUNT(*) AS messages,
                    COUNT(*) FILTER (WHERE NOT m.seen) AS unread, MAX(m.received_at) AS last_received_at
             FROM ({$listed}) listed JOIN message m ON m.thread_id = listed.id
             WHERE m.from_address <> ALL(CAST(:own AS TEXT[]))
             GROUP BY m.from_address
             ORDER BY conversations DESC, messages DESC, address
             LIMIT :limit",
            [...$parameters, 'own' => self::textArray($this->ownAddresses($owner)), 'limit' => self::MAX_SENDERS],
        );

        return [
            'conversations' => (int) $totals['conversations'],
            'messages' => (int) $totals['messages'],
            'unread' => (int) $totals['unread'],
            'senders' => array_map(static fn (array $row): array => [
                'address' => $row['address'],
                'name' => $row['name'],
                'conversations' => (int) $row['conversations'],
                'messages' => (int) $row['messages'],
                'unread' => (int) $row['unread'],
                'lastReceivedAt' => new \DateTimeImmutable($row['last_received_at']),
            ], $rows),
        ];
    }

    /** @return list<string> */
    public function ownAddresses(User $owner): array
    {
        return array_map(static fn ($account): string => $account->getEmailAddress(), $this->accounts->findOwnedBy($owner));
    }

    /**
     * @param list<string> $values
     */
    public static function textArray(array $values): string
    {
        return '{'.implode(',', array_map(static fn (string $value): string => '"'.addcslashes($value, '"\\').'"', $values)).'}';
    }
}
