<?php

declare(strict_types=1);

namespace App\Bulk;

use App\Entity\User;
use App\Enum\BuiltinCategory;
use App\Enum\BulkArea;
use App\Enum\MessageAction;
use App\Repository\CategoryRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Suggests what to clean up, from fixed rules over the user's own mail, each with what it counts and one bulk action
 * that does it. No guessing: every suggestion says which rule it follows and what it found.
 *
 * - `newsletter`: a sender whose mail carries List-Unsubscribe or List-Id has at least {@see self::NEWSLETTER_UNREAD}
 *   unread in the inboxes, and the user opened none of their mail in the last {@see self::WINDOW_DAYS} days.
 *   Archive their conversations in the inboxes.
 * - `unopenedSender`: the same for any other sender, from {@see self::SENDER_UNREAD} unread.
 * - `oldInSmartInbox`: at least {@see self::OLD_MIN} conversations in the Notifications or Advertising smart inbox whose
 *   newest message is older than {@see self::OLD_DAYS} days. Archive them.
 * - `largeOld`: at least {@see self::LARGE_MIN} conversations outside Trash, Spam and Drafts with a message of
 *   {@see self::LARGE_BYTES} bytes or more, whose newest message is older than {@see self::LARGE_DAYS} days. Move them
 *   to Trash.
 */
final readonly class CleanupSuggestions
{
    public const int WINDOW_DAYS = 90;
    public const int NEWSLETTER_UNREAD = 3;
    public const int SENDER_UNREAD = 10;
    public const int MAX_SENDERS = 20;
    public const int OLD_DAYS = 30;
    public const int OLD_MIN = 10;
    public const int LARGE_DAYS = 365;
    public const int LARGE_BYTES = 5 * 1024 * 1024;
    public const int LARGE_MIN = 3;

    public function __construct(
        private Connection $connection,
        private BulkSelection $selection,
        private SenderStatistics $statistics,
        private CategoryRepository $smartInboxes,
    ) {
    }

    /**
     * @return list<CleanupSuggestion> the biggest first within each rule, rules in the order above
     */
    public function forUser(User $owner, \DateTimeImmutable $now): array
    {
        return [
            ...$this->senders($owner, $now),
            ...$this->oldInSmartInboxes($owner, $now),
            ...$this->largeOld($owner, $now),
        ];
    }

    /** @return list<CleanupSuggestion> */
    private function senders(User $owner, \DateTimeImmutable $now): array
    {
        [$where, $parameters] = $this->selection->where($owner, new BulkScope(BulkArea::Inbox), Uuid::v7());

        /** @var list<array{address: string, name: ?string, conversations: int, unread: int, received: int, listed: int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "WITH senders AS (
                 SELECT m.from_address AS address,
                        (ARRAY_AGG(m.from_name ORDER BY m.received_at DESC) FILTER (WHERE m.from_name IS NOT NULL AND m.from_name <> ''))[1] AS name,
                        COUNT(DISTINCT m.thread_id) AS conversations, COUNT(*) FILTER (WHERE NOT m.seen) AS unread
                 FROM (SELECT t.id FROM thread t JOIN mail_account a ON a.id = t.mail_account_id WHERE {$where}) listed
                 JOIN message m ON m.thread_id = listed.id
                 WHERE m.from_address <> ALL(CAST(:own AS TEXT[]))
                 GROUP BY m.from_address
                 HAVING COUNT(*) FILTER (WHERE NOT m.seen) >= :minUnread
             )
             SELECT s.address, s.name, s.conversations, s.unread, w.received, w.listed
             FROM senders s
             JOIN LATERAL (
                 SELECT COUNT(*) AS received, COUNT(*) FILTER (WHERE wm.seen) AS seen,
                        COUNT(*) FILTER (WHERE sig.headers->>'list-unsubscribe' IS NOT NULL OR sig.headers->>'list-id' IS NOT NULL) AS listed
                 FROM message wm JOIN mail_account wa ON wa.id = wm.mail_account_id
                 LEFT JOIN message_signals sig ON sig.message_id = wm.id
                 WHERE wa.owner_id = :owner AND wm.from_address = s.address AND wm.received_at >= :since
             ) w ON w.seen = 0
             ORDER BY s.unread DESC, s.address
             LIMIT :limit",
            [
                ...$parameters,
                'own' => SenderStatistics::textArray($this->statistics->ownAddresses($owner)),
                'minUnread' => min(self::NEWSLETTER_UNREAD, self::SENDER_UNREAD),
                'since' => $now->modify(\sprintf('-%d days', self::WINDOW_DAYS))->format('Y-m-d H:i:s'),
                'limit' => self::MAX_SENDERS * 2,
            ],
        );

        $suggestions = [];
        foreach ($rows as $row) {
            $newsletter = (int) $row['listed'] > 0;
            $unread = (int) $row['unread'];
            if ($unread < ($newsletter ? self::NEWSLETTER_UNREAD : self::SENDER_UNREAD)) {
                continue;
            }
            $suggestions[] = new CleanupSuggestion(
                ($newsletter ? 'newsletter:' : 'unopenedSender:').$row['address'],
                $newsletter ? CleanupSuggestion::NEWSLETTER : CleanupSuggestion::UNOPENED_SENDER,
                MessageAction::Archive,
                BulkArea::Inbox,
                conversations: (int) $row['conversations'],
                unread: $unread,
                sender: $row['address'],
                senderName: $row['name'],
                windowDays: self::WINDOW_DAYS,
            );
            if (\count($suggestions) === self::MAX_SENDERS) {
                break;
            }
        }

        return $suggestions;
    }

    /** @return list<CleanupSuggestion> */
    private function oldInSmartInboxes(User $owner, \DateTimeImmutable $now): array
    {
        $suggestions = [];
        $before = $now->modify(\sprintf('-%d days', self::OLD_DAYS));
        foreach ($this->smartInboxes->findOwnedBy($owner) as $smartInbox) {
            if (!\in_array($smartInbox->getBuiltin(), [BuiltinCategory::Notification, BuiltinCategory::Advertise], true)) {
                continue;
            }
            $count = $this->selection->count($owner, new BulkScope(BulkArea::SmartInbox, $smartInbox, before: $before), Uuid::v7());
            if ($count['conversations'] < self::OLD_MIN) {
                continue;
            }
            $suggestions[] = new CleanupSuggestion(
                'oldInSmartInbox:'.$smartInbox->getId()->toRfc4122(),
                CleanupSuggestion::OLD_IN_SMART_INBOX,
                MessageAction::Archive,
                BulkArea::SmartInbox,
                conversations: $count['conversations'],
                smartInboxId: $smartInbox->getId()->toRfc4122(),
                olderThanDays: self::OLD_DAYS,
            );
        }
        usort($suggestions, static fn (CleanupSuggestion $a, CleanupSuggestion $b): int => $b->conversations <=> $a->conversations);

        return $suggestions;
    }

    /** @return list<CleanupSuggestion> */
    private function largeOld(User $owner, \DateTimeImmutable $now): array
    {
        $scope = new BulkScope(BulkArea::Mailbox, before: $now->modify(\sprintf('-%d days', self::LARGE_DAYS)), largerThan: self::LARGE_BYTES);
        [$where, $parameters] = $this->selection->where($owner, $scope, Uuid::v7());

        /** @var array{conversations: int, bytes: int|string|null} $row */
        $row = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS conversations, SUM((SELECT SUM(bm.size) FROM message bm WHERE bm.thread_id = t.id)) AS bytes
             FROM thread t JOIN mail_account a ON a.id = t.mail_account_id WHERE {$where}",
            $parameters,
        );
        if ((int) $row['conversations'] < self::LARGE_MIN) {
            return [];
        }

        return [new CleanupSuggestion(
            'largeOld',
            CleanupSuggestion::LARGE_OLD,
            MessageAction::Trash,
            BulkArea::Mailbox,
            conversations: (int) $row['conversations'],
            bytes: (int) $row['bytes'],
            olderThanDays: self::LARGE_DAYS,
            largerThan: self::LARGE_BYTES,
        )];
    }
}
