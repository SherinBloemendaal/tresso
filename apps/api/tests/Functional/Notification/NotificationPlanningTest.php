<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Calendar\CalendarStore;
use App\Calendar\ICalendar\CalendarDocument;
use App\Entity\Calendar;
use App\Entity\CalendarObject;
use App\Entity\ScheduledNotification;
use App\Notification\NotificationPlanner;
use App\Repository\CalendarRepository;
use App\Repository\UserRepository;
use App\Tests\Functional\AuthenticatesUsers;
use App\Tests\Functional\Calendar\CalendarTestHelpers;
use App\Tests\Functional\DatabaseTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Planning at a fixed moment, so the dates (and a daylight saving change) are known: series, overrides, all-day
 * events, and edits before delivery.
 */
final class NotificationPlanningTest extends DatabaseTestCase
{
    use AuthenticatesUsers;
    use CalendarTestHelpers;

    /** Tuesday 20 October 2026; summer time in Amsterdam ends on Sunday 25 October. */
    private const string NOW = '2026-10-20T09:00:00+00:00';

    /** @var array<string, string> */
    private array $kim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapOAuthClients();
        $this->createUser('kim@example.test', 'Kim');
        $this->kim = $this->userHeaders('kim@example.test', ['calendar:read', 'calendar:write', 'settings:read', 'settings:write']);
        $this->client->request('PATCH', '/api/v1/notification-settings', server: [...$this->kim, 'CONTENT_TYPE' => 'application/merge-patch+json'], content: json_encode(['timeZone' => 'Europe/Amsterdam'], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
    }

    public function testASeriesKeepsItsLocalTimeAcrossTheClockChangeAndOverridesMoveTheirReminder(): void
    {
        $this->store('standup@test', [
            'BEGIN:VEVENT', 'UID:standup@test', 'DTSTAMP:20261019T080000Z', 'DTSTART;TZID=Europe/Amsterdam:20261021T090000', 'DURATION:PT15M',
            'RRULE:FREQ=WEEKLY;COUNT=3', 'SUMMARY:Standup', 'BEGIN:VALARM', 'ACTION:DISPLAY', 'TRIGGER:-PT10M', 'END:VALARM', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:standup@test', 'DTSTAMP:20261019T080000Z', 'RECURRENCE-ID;TZID=Europe/Amsterdam:20261104T090000',
            'DTSTART;TZID=Europe/Amsterdam:20261104T113000', 'DURATION:PT15M', 'SUMMARY:Standup, later', 'BEGIN:VALARM', 'ACTION:DISPLAY', 'TRIGGER:-PT10M', 'END:VALARM', 'END:VEVENT',
        ]);

        $this->planAt(self::NOW);

        // 09:00 in Amsterdam is 07:00 UTC in summer time and 08:00 UTC after it.
        self::assertSame(['2026-10-21T06:50:00+00:00', '2026-10-28T07:50:00+00:00'], $this->fireTimes(), 'Planned eight days ahead.');

        $this->planAt('2026-10-30T08:00:00+00:00');

        self::assertContains('2026-11-04T10:20:00+00:00', $this->fireTimes(), 'The override at 11:30 brings its own reminder.');
        self::assertNotContains('2026-11-04T07:50:00+00:00', $this->fireTimes());
    }

    public function testAllDayRemindersComeAtTheirTimeInTheUsersZone(): void
    {
        $this->store('holiday@test', [
            'BEGIN:VEVENT', 'UID:holiday@test', 'DTSTAMP:20261019T080000Z', 'DTSTART;VALUE=DATE:20261023', 'DTEND;VALUE=DATE:20261024', 'SUMMARY:Holiday', 'END:VEVENT',
        ]);

        $this->planAt(self::NOW);

        // The default rule for all-day events: 09:00 the day before, in Amsterdam.
        self::assertSame(['2026-10-22T07:00:00+00:00'], $this->fireTimes());
    }

    public function testAnEditBeforeDeliveryMovesThePlannedReminder(): void
    {
        $lines = static fn (string $start): array => [
            'BEGIN:VEVENT', 'UID:review@test', 'DTSTAMP:20261019T080000Z', 'DTSTART;TZID=Europe/Amsterdam:'.$start, 'DURATION:PT30M', 'SUMMARY:Review',
            'BEGIN:VALARM', 'ACTION:DISPLAY', 'TRIGGER:-PT15M', 'END:VALARM', 'END:VEVENT',
        ];
        $this->store('review@test', $lines('20261022T140000'));
        $this->planAt(self::NOW);
        self::assertSame(['2026-10-22T11:45:00+00:00'], $this->fireTimes());

        $this->store('review@test', $lines('20261022T160000'));
        $this->planAt(self::NOW);

        self::assertSame(['2026-10-22T13:45:00+00:00'], $this->fireTimes(), 'One notification, at the new time.');
    }

    /**
     * Stores an event the way a provider sync would (saving it queues a plan at the real time, which planAt() then
     * redoes at the fixed one).
     *
     * @param list<string> $lines
     */
    private function store(string $uid, array $lines): void
    {
        $calendar = $this->calendar();
        $ical = implode("\r\n", ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Test//EN', ...$lines, 'END:VCALENDAR', '']);
        $entityManager = $this->entityManager();
        $object = $entityManager->getRepository(CalendarObject::class)->findOneBy(['calendar' => $calendar, 'uid' => $uid]) ?? new CalendarObject($calendar, $uid, '');
        $entityManager->wrapInTransaction(static fn () => static::getContainer()->get(CalendarStore::class)->save($object, CalendarDocument::parse($ical)));
    }

    private function planAt(string $now): void
    {
        $entityManager = $this->entityManager();
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'kim@example.test']) ?? self::fail('No user.');
        $entityManager->wrapInTransaction(static fn () => static::getContainer()->get(NotificationPlanner::class)->planUser($user, new \DateTimeImmutable($now)));
        $entityManager->clear();
    }

    /** @return list<string> */
    private function fireTimes(): array
    {
        $times = array_map(
            static fn (ScheduledNotification $n): string => $n->getFireAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            $this->entityManager()->getRepository(ScheduledNotification::class)->findBy([], ['fireAt' => 'ASC']),
        );

        return array_values(array_unique($times));
    }

    private function calendar(): Calendar
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'kim@example.test']) ?? self::fail('No user.');

        return static::getContainer()->get(CalendarRepository::class)->findOneBy(['owner' => $user, 'mailAccount' => null]) ?? self::fail('No calendar.');
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
