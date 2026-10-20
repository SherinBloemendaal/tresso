<?php

declare(strict_types=1);

namespace App\Tests\Functional\Calendar;

use App\Calendar\Invitation\ApplyCalendarInvitation;
use App\Calendar\Invitation\InvitationApplier;
use App\Entity\OutgoingMessage;
use App\Storage\BlobVault;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Shared steps of the calendar tests.
 *
 * @property KernelBrowser $client
 */
trait CalendarTestHelpers
{
    /**
     * @param array<string, string> $headers
     *
     * @return array<mixed>
     */
    private function getJson(string $uri, array $headers): array
    {
        $this->client->request('GET', $uri, server: $headers);
        self::assertResponseIsSuccessful();

        return $this->decodedResponse();
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<array<string, mixed>>
     */
    private function occurrences(array $headers, string $from, string $to, string $timeZone = 'UTC'): array
    {
        /** @var list<array<string, mixed>> */
        return array_values($this->getJson('/api/v1/calendar-occurrences?'.http_build_query(['from' => $from, 'to' => $to, 'timeZone' => $timeZone]), $headers));
    }

    /**
     * @param array<string, string> $headers
     */
    private function localCalendarId(array $headers): string
    {
        foreach ($this->getJson('/api/v1/calendars', $headers) as $calendar) {
            if (\is_array($calendar) && null === $calendar['mailAccountId'] && \is_string($calendar['id'])) {
                return $calendar['id'];
            }
        }
        self::fail('The user has no local calendar.');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function event(string $calendarId, array $overrides = []): array
    {
        return [...[
            'calendarId' => $calendarId,
            'summary' => 'Weekly sync',
            'start' => ['dateTime' => '2026-10-19T09:00:00', 'timeZone' => 'Europe/Amsterdam'],
            'end' => ['dateTime' => '2026-10-19T09:30:00', 'timeZone' => 'Europe/Amsterdam'],
        ], ...$overrides];
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * Runs the invitation handlers the ingest queued, as the Messenger worker would.
     */
    private function applyQueuedInvitations(): void
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof ApplyCalendarInvitation) {
                static::getContainer()->get(InvitationApplier::class)($message);
            }
        }
        $transport->reset();
    }

    /**
     * @return list<OutgoingMessage>
     */
    private function outgoing(): array
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(OutgoingMessage::class)->findBy([], ['createdAt' => 'ASC']);
    }

    private function rawOutgoing(OutgoingMessage $message): string
    {
        return static::getContainer()->get(BlobVault::class)->read($message->getMailAccount()->getOwner()->getId(), $message->getRawObjectKey());
    }
}
