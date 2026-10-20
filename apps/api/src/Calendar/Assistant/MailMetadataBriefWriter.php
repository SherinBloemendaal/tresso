<?php

declare(strict_types=1);

namespace App\Calendar\Assistant;

use App\Calendar\SelfAddresses;
use App\Entity\CalendarEvent;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The brief from what Tresso already knows: the other people of the meeting and the user's threads with them in the
 * last 60 days, newest first. No content leaves the server and nothing is summarized.
 */
#[AsAlias(MeetingBriefWriter::class)]
final readonly class MailMetadataBriefWriter implements MeetingBriefWriter
{
    private const int MAX_THREADS = 5;
    private const string LOOKBACK = '-60 days';

    public function __construct(
        private Connection $connection,
        private SelfAddresses $selfAddresses,
    ) {
    }

    public function writesSummaries(): bool
    {
        return false;
    }

    public function brief(CalendarEvent $event, string $occurrenceKey): MeetingBrief
    {
        $owner = $event->getCalendar()->getOwner();
        $self = $this->selfAddresses->of($owner);

        $people = [];
        $organizer = $event->getOrganizer();
        if (null !== $organizer && !\in_array($organizer['address'], $self, true)) {
            $people[$organizer['address']] = ['address' => $organizer['address'], 'name' => $organizer['name'], 'response' => 'organizer'];
        }
        foreach ($event->getAttendees() as $attendee) {
            if (\in_array($attendee['address'], $self, true) || isset($people[$attendee['address']]) || \in_array($attendee['kind'], ['room', 'resource'], true)) {
                continue;
            }
            $people[$attendee['address']] = ['address' => $attendee['address'], 'name' => $attendee['name'], 'response' => $attendee['partstat']];
        }
        if ([] === $people) {
            return new MeetingBrief([], [], null);
        }

        $addresses = '{'.implode(',', array_map(static fn (string $address): string => '"'.str_replace('"', '', $address).'"', array_keys($people))).'}';
        /** @var list<array{thread_id: string, mail_account_id: string, subject: string, last_message_at: string, message_count: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT t.id AS thread_id, t.mail_account_id, t.subject, t.last_message_at, t.message_count
             FROM thread t JOIN mail_account a ON a.id = t.mail_account_id
             WHERE a.owner_id = :owner AND t.last_message_at > :since AND EXISTS (
                 SELECT 1 FROM message m WHERE m.thread_id = t.id AND m.received_at > :since AND (
                     m.from_address = ANY(CAST(:addresses AS TEXT[]))
                     OR EXISTS (SELECT 1 FROM jsonb_array_elements(COALESCE(CAST(m.recipients AS JSONB)->'to', '[]') || COALESCE(CAST(m.recipients AS JSONB)->'cc', '[]')) r
                                WHERE r->>'address' = ANY(CAST(:addresses AS TEXT[])))
                 )
             )
             ORDER BY t.last_message_at DESC
             LIMIT :limit",
            [
                'owner' => $owner->getId()->toRfc4122(),
                'since' => new \DateTimeImmutable(self::LOOKBACK)->format('Y-m-d H:i:s'),
                'addresses' => $addresses,
                'limit' => self::MAX_THREADS,
            ],
        );

        return new MeetingBrief(
            array_values($people),
            array_map(static fn (array $row): array => [
                'threadId' => $row['thread_id'],
                'mailAccountId' => $row['mail_account_id'],
                'subject' => $row['subject'],
                'lastMessageAt' => new \DateTimeImmutable($row['last_message_at'])->format(\DATE_ATOM),
                'messageCount' => (int) $row['message_count'],
            ], $rows),
            null,
        );
    }
}
