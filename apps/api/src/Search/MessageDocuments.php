<?php

declare(strict_types=1);

namespace App\Search;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;

/**
 * Reads a user's messages as search documents, straight from the database.
 *
 * A document holds what search matches on (subject, sender, recipients, snippet, body text, attachment names), what
 * filters need (owner, account, folders, address tokens, attachment flag, received time) and what a result shows.
 * Folders are stored by id, so renaming a folder or changing its role needs no reindex.
 */
final readonly class MessageDocuments
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Messages whose change number lies in ($fromSeq, $toSeq], in pages ordered by change number and id.
     *
     * @return iterable<list<array<string, mixed>>>
     */
    public function changedBetween(Uuid $ownerId, int $fromSeq, int $toSeq, int $pageSize): iterable
    {
        $afterSeq = $fromSeq;
        // Above every id: the first page starts after $fromSeq itself.
        $afterId = 'ffffffff-ffff-ffff-ffff-ffffffffffff';

        do {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->connection->fetchAllAssociative(
                "SELECT m.id, m.thread_id, m.mail_account_id, m.subject, m.from_address, m.from_name, m.recipients, m.snippet,
                        m.received_at, m.seen, m.flagged, m.has_attachments, m.change_seq, t.text AS body_text,
                        COALESCE((SELECT json_agg(l.folder_id ORDER BY l.folder_id) FROM message_location l WHERE l.message_id = m.id), '[]') AS folder_ids,
                        COALESCE((SELECT json_agg(json_build_object('id', a.id, 'filename', a.filename, 'contentType', a.content_type, 'size', a.size) ORDER BY a.id)
                                  FROM attachment a WHERE a.message_id = m.id AND NOT a.inline), '[]') AS attachments
                 FROM message m
                 JOIN mail_account ma ON ma.id = m.mail_account_id
                 LEFT JOIN message_text t ON t.message_id = m.id
                 WHERE ma.owner_id = :owner
                   AND m.change_seq > :from
                   AND m.change_seq <= :to
                   AND (m.change_seq, m.id) > (:afterSeq, CAST(:afterId AS UUID))
                 ORDER BY m.change_seq, m.id
                 LIMIT :limit",
                ['owner' => $ownerId->toRfc4122(), 'from' => $fromSeq, 'to' => $toSeq, 'afterSeq' => $afterSeq, 'afterId' => $afterId, 'limit' => $pageSize],
            );
            if ([] === $rows) {
                return;
            }

            yield array_map(fn (array $row): array => $this->document($ownerId, $row), $rows);

            $last = $rows[\count($rows) - 1];
            $afterSeq = self::int($last['change_seq']);
            $afterId = self::string($last['id']);
        } while (\count($rows) === $pageSize);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function document(Uuid $ownerId, array $row): array
    {
        /** @var array<string, list<array{address: string, name: ?string}>> $recipients */
        $recipients = self::json($row['recipients']);
        /** @var list<string> $folderIds */
        $folderIds = self::json($row['folder_ids']);
        /** @var list<array{id: string, filename: string, contentType: string, size: int}> $attachments */
        $attachments = self::json($row['attachments']);

        $fromAddress = self::string($row['from_address']);
        $fromName = null === $row['from_name'] ? null : MessageIndex::clean(self::string($row['from_name']));

        $recipientTexts = [];
        $toTokens = [];
        foreach (['to', 'cc', 'bcc'] as $header) {
            foreach ($recipients[$header] ?? [] as $recipient) {
                $name = null === $recipient['name'] ? null : MessageIndex::clean($recipient['name']);
                $recipientTexts[] = trim(($name ?? '').' '.$recipient['address']);
                $toTokens = [...$toTokens, ...AddressTokens::forAddress($recipient['address'], $name)];
            }
        }

        $receivedAt = $this->connection->convertToPHPValue($row['received_at'], Types::DATETIME_IMMUTABLE);
        \assert($receivedAt instanceof \DateTimeImmutable);

        return [
            'id' => self::string($row['id']),
            'ownerId' => $ownerId->toRfc4122(),
            'accountId' => self::string($row['mail_account_id']),
            'threadId' => self::string($row['thread_id']),
            'folderIds' => $folderIds,
            'subject' => MessageIndex::clean(self::string($row['subject'])),
            'fromAddress' => $fromAddress,
            'fromName' => $fromName,
            'fromTokens' => AddressTokens::forAddress($fromAddress, $fromName),
            'recipients' => $recipientTexts,
            'toTokens' => array_values(array_unique($toTokens)),
            'snippet' => MessageIndex::clean(self::string($row['snippet'])),
            'bodyText' => null === $row['body_text'] ? '' : MessageIndex::clean(self::string($row['body_text'])),
            'attachments' => array_map(static fn (array $a): array => [...$a, 'filename' => MessageIndex::clean($a['filename'])], $attachments),
            'hasAttachments' => (bool) $row['has_attachments'],
            'seen' => (bool) $row['seen'],
            'flagged' => (bool) $row['flagged'],
            'receivedAt' => $receivedAt->getTimestamp(),
        ];
    }

    /**
     * @return array<mixed>
     */
    private static function json(mixed $value): array
    {
        $decoded = json_decode(self::string($value), true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \UnexpectedValueException('Expected a JSON array from the database.');
        }

        return $decoded;
    }

    private static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException(\sprintf('Expected a string from the database, got %s.', get_debug_type($value)));
        }

        return $value;
    }

    private static function int(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException(\sprintf('Expected a number from the database, got %s.', get_debug_type($value)));
        }

        return (int) $value;
    }
}
