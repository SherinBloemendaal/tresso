<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Entity\ScheduledNotification;
use App\Notification\NotificationDeliverer;
use App\Notification\NotificationHandlers;
use App\Notification\PlanCalendarObject;
use App\Notification\PlanUserNotifications;
use App\Notification\SweepNotifications;
use App\Tests\Functional\AuthenticatesUsers;
use App\Tests\Functional\Calendar\CalendarTestHelpers;
use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\MockHttpRouter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class NotificationTest extends DatabaseTestCase
{
    use AuthenticatesUsers;
    use CalendarTestHelpers;

    private const string PUSH_ENDPOINT = 'https://push.example.test/subscriptions/1';
    // A subscription key pair made for these tests (P-256 public point and auth secret).
    private const string P256DH = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    private const string AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';
    private const string EXPO_TOKEN = 'ExponentPushToken[abc123]';

    /** @var array<string, string> */
    private array $kim;

    private string $calendarId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapOAuthClients();
        $this->createUser('kim@example.test', 'Kim');
        $this->kim = $this->userHeaders('kim@example.test', ['mail:read', 'calendar:read', 'calendar:write', 'settings:read', 'settings:write']);
        $this->calendarId = $this->localCalendarId($this->kim);
    }

    public function testNewUsersStartWithDefaultRulesAndSettings(): void
    {
        $rules = $this->getJson('/api/v1/reminder-rules', $this->kim);
        self::assertSame(['Timed events', 'All-day events'], array_column($rules, 'name'));
        self::assertSame([[['minutesBefore' => 10, 'dayBeforeAt' => null, 'channels' => ['phone', 'desktop']]], [['minutesBefore' => null, 'dayBeforeAt' => '09:00', 'channels' => ['phone', 'desktop']]]], array_column($rules, 'reminders'));

        $settings = $this->getJson('/api/v1/notification-settings', $this->kim);
        self::assertFalse($settings['quietHoursEnabled']);
        self::assertSame('kim@example.test', $settings['effectiveEmailAddress']);
        self::assertNotNull($this->getJson('/api/v1/notification-devices/web-push-key', $this->kim)['publicKey']);
    }

    public function testPlansTheDefaultReminderOfAnEvent(): void
    {
        $start = $this->inMinutes(2 * 24 * 60);
        $this->createEvent($start);

        $planned = $this->notifications();
        self::assertCount(1, $planned);
        self::assertSame('reminder', $planned[0]['kind']);
        self::assertSame(['phone', 'desktop'], $planned[0]['channels']);
        self::assertSame($start->modify('-10 minutes')->format(\DATE_ATOM), $this->atom($planned[0]['fireAt']));
        self::assertNull($planned[0]['deliveredAt']);
    }

    public function testDeliversOnceToEveryDeviceAndByEmail(): void
    {
        $this->registerDevices();
        $this->createEvent($this->inMinutes(6), ['useDefaultReminders' => false, 'reminders' => [['minutesBefore' => 5, 'channels' => ['phone', 'desktop', 'email']]]]);
        $id = $this->onlyNotificationId();

        $this->fire($id);
        $this->fire($id);

        $pushes = array_values(array_filter($this->http()->calls(), static fn (array $call): bool => self::PUSH_ENDPOINT === $call['url']));
        self::assertCount(1, $pushes, 'A device is notified once, however often the notification fires.');
        self::assertContains('Content-Encoding: aes128gcm', $pushes[0]['headers']);
        self::assertNotEmpty(array_filter($pushes[0]['headers'], static fn (string $header): bool => str_starts_with($header, 'Authorization: vapid t=')));

        $expo = array_values(array_filter($this->http()->calls(), static fn (array $call): bool => str_starts_with($call['url'], 'https://exp.host/')));
        self::assertCount(1, $expo);
        $body = json_decode($expo[0]['body'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(self::EXPO_TOKEN, $body['to']);
        self::assertSame('Standup', $body['title']);
        self::assertSame('default', $body['sound']);

        self::assertEmailCount(1);
        self::assertEmailHeaderSame(self::getMailerMessage() ?? self::fail('No email.'), 'Subject', 'Standup');

        $delta = $this->getJson('/api/v1/sync/changes?since=0', $this->kim);
        self::assertIsArray($delta['notifications']);
        self::assertSame([$id], array_column($delta['notifications'], 'id'), 'The desktop app finds the delivered notification in the delta.');
    }

    public function testAReminderForThePhoneReachesOnlyThePhone(): void
    {
        $this->registerDevices();
        $this->createEvent($this->inMinutes(6), ['useDefaultReminders' => false, 'reminders' => [['minutesBefore' => 5, 'channels' => ['phone']]]]);

        $this->fire($this->onlyNotificationId());

        self::assertCount(1, array_filter($this->http()->calls(), static fn (array $call): bool => str_starts_with($call['url'], 'https://exp.host/')));
        self::assertCount(0, array_filter($this->http()->calls(), static fn (array $call): bool => self::PUSH_ENDPOINT === $call['url']));
        self::assertEmailCount(0);
    }

    public function testTheUserChoosesTheChannelsPerKindOfNotification(): void
    {
        $this->patchSettings(['channelsByKind' => ['leave_now' => ['phone', 'email'], 'brief' => []]]);
        self::assertEquals(['leave_now' => ['phone', 'email'], 'brief' => []], $this->getJson('/api/v1/notification-settings', $this->kim)['channelsByKind']);

        $this->patchSettings(['channelsByKind' => ['brief' => null]]);
        self::assertEquals(['leave_now' => ['phone', 'email']], $this->getJson('/api/v1/notification-settings', $this->kim)['channelsByKind'], 'Null goes back to the default.');

        $this->createEvent($this->inMinutes(3 * 60), ['location' => 'Herengracht 1, Amsterdam', 'travelMinutes' => 20]);
        $leave = array_values(array_filter($this->notifications(), static fn (array $n): bool => 'leave_now' === $n['kind']));
        self::assertSame(['phone', 'email'], $leave[0]['channels']);
    }

    public function testRefusesUnknownChannels(): void
    {
        $this->client->request('PATCH', '/api/v1/notification-settings', server: [...$this->kim, 'CONTENT_TYPE' => 'application/merge-patch+json'], content: json_encode(['channelsByKind' => ['brief' => ['pager']]], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    public function testForgetsSubscriptionsThePushServiceNoLongerKnows(): void
    {
        $this->registerDevices();
        $this->http()->respond(self::PUSH_ENDPOINT, '', 410);
        $this->createEvent($this->inMinutes(12));

        $this->fire($this->onlyNotificationId());

        self::assertSame(['expo', 'desktop'], array_column($this->getJson('/api/v1/notification-devices', $this->kim), 'kind'));
    }

    public function testQuietHoursMoveAReminderToTheirEndOrDeliverItSilently(): void
    {
        $this->registerDevices();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->patchSettings(['timeZone' => 'UTC', 'quietHoursEnabled' => true, 'quietStart' => $now->modify('-1 hour')->format('H:i'), 'quietEnd' => $now->modify('+1 hour')->format('H:i')]);

        $this->createEvent($this->inMinutes(11), ['summary' => 'During quiet hours']);
        // Its reminder falls in the quiet hours, the event itself after them.
        $this->createEvent($this->inMinutes(90), ['summary' => 'After quiet hours', 'useDefaultReminders' => false, 'reminders' => [['minutesBefore' => 60, 'channels' => ['phone', 'desktop']]]]);
        $byTitle = array_column($this->notifications(), null, 'title');

        $this->fire(self::string($byTitle['During quiet hours']['id']));
        $this->fire(self::string($byTitle['After quiet hours']['id']));

        $byTitle = array_column($this->notifications(), null, 'title');
        self::assertNotNull($byTitle['During quiet hours']['deliveredAt']);
        self::assertTrue($byTitle['During quiet hours']['silent']);
        self::assertNull($byTitle['After quiet hours']['deliveredAt']);
        self::assertSame($now->modify('+1 hour')->format('Y-m-d\TH:i'), substr($this->atom($byTitle['After quiet hours']['fireAt']), 0, 16), 'It waits for the end of the quiet hours.');
        $pushes = array_filter($this->http()->calls(), static fn (array $call): bool => self::PUSH_ENDPOINT === $call['url']);
        self::assertCount(1, $pushes);
        self::assertContains('Urgency: low', array_values($pushes)[0]['headers'], 'A quiet notification asks for no attention.');
    }

    public function testFocusQuietsInvitationsDuringABusyMeetingButNotOwnMeetings(): void
    {
        $this->registerDevices();
        $this->createEvent($this->inMinutes(-10), ['summary' => 'Workshop']);
        $start = $this->inMinutes(11)->format('Ymd\THis\Z');
        $this->storeInvitation('call@finance.example', 'Call with Anna', $start);

        $call = array_values(array_filter($this->notifications(), static fn (array $n): bool => 'Call with Anna' === $n['title']));
        self::assertCount(1, $call);
        $this->fire(self::string($call[0]['id']));

        $call = array_values(array_filter($this->notifications(), static fn (array $n): bool => 'Call with Anna' === $n['title']));
        self::assertNotNull($call[0]['deliveredAt']);
        self::assertTrue($call[0]['silent'], 'The user is in the workshop.');

        $this->createEvent($this->inMinutes(11), ['summary' => 'Planning']);
        $own = array_values(array_filter($this->notifications(), static fn (array $n): bool => 'Planning' === $n['title']));
        $this->fire(self::string($own[0]['id']));
        $own = array_values(array_filter($this->notifications(), static fn (array $n): bool => 'Planning' === $n['title']));
        self::assertNotNull($own[0]['deliveredAt']);
        self::assertFalse($own[0]['silent'], 'The user\'s own meetings break through.');
    }

    public function testDismissedOnOneDeviceAndSnoozedForLater(): void
    {
        $this->registerDevices();
        $this->createEvent($this->inMinutes(11));
        $id = $this->onlyNotificationId();
        $this->fire($id);

        $this->client->jsonRequest('POST', '/api/v1/notifications/'.$id.'/snooze', ['minutes' => 5], $this->kim);
        self::assertResponseIsSuccessful();
        self::assertNull($this->decodedResponse()['deliveredAt']);
        $this->fire($id, new \DateTimeImmutable('+5 minutes'));
        $pushes = array_filter($this->http()->calls(), static fn (array $call): bool => self::PUSH_ENDPOINT === $call['url']);
        self::assertCount(2, $pushes, 'A snoozed notification is delivered again.');

        $this->client->jsonRequest('POST', '/api/v1/notifications/'.$id.'/dismiss', [], $this->kim);
        self::assertResponseIsSuccessful();
        $delta = $this->getJson('/api/v1/sync/changes?since=0', $this->kim);
        self::assertIsArray($delta['notifications']);
        self::assertNotContains(null, array_column($delta['notifications'], 'dismissedAt'));
        self::assertCount(1, $delta['notifications']);
    }

    public function testLeaveNowUsesTheTravelTime(): void
    {
        $start = $this->inMinutes(2 * 24 * 60);
        $this->createEvent($start, ['location' => 'Café Loos, Amsterdam', 'travelMinutes' => 20]);

        $leave = array_values(array_filter($this->notifications(), static fn (array $n): bool => 'leave_now' === $n['kind']));
        self::assertCount(1, $leave);
        self::assertSame($start->modify('-25 minutes')->format(\DATE_ATOM), $this->atom($leave[0]['fireAt']));
        self::assertSame('Leave now: about 20 minutes to Café Loos, Amsterdam.', $leave[0]['body']);
    }

    public function testRulesAddTheBriefAndTheFollowUpForMeetings(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/reminder-rules', [
            'name' => 'Meetings',
            'allDay' => false,
            'withAttendees' => true,
            'reminders' => [['minutesBefore' => 15, 'channels' => ['phone', 'desktop']]],
            'briefMinutes' => 10,
            'followUp' => true,
        ], $this->kim);
        self::assertResponseStatusCodeSame(201);
        $this->runQueuedPlanning();

        $this->client->jsonRequest('POST', '/api/v1/mail-accounts', [
            'emailAddress' => 'kim@company.example',
            'displayName' => 'Kim Visser',
            'username' => 'kim@company.example',
            'password' => 'app-password-1',
            'imap' => ['host' => 'imap.company.example', 'port' => 993, 'security' => 'tls'],
            'smtp' => ['host' => 'smtp.company.example', 'port' => 465, 'security' => 'tls'],
        ], $this->kim);
        $accountId = $this->responseString('id');

        $start = $this->inMinutes(2 * 24 * 60);
        $this->createEvent($start, ['summary' => 'Budget review', 'attendees' => [['address' => 'anna@finance.example', 'name' => 'Anna de Vries']], 'organizerMailAccountId' => $accountId]);
        $this->createEvent($start->modify('+2 hours'), ['summary' => 'Focus time']);

        $byKind = [];
        foreach ($this->notifications() as $notification) {
            $byKind[self::string($notification['title']).'/'.self::string($notification['kind'])] = $notification;
        }
        self::assertSame(['Budget review/reminder', 'Brief: Budget review/brief', 'After: Budget review/follow_up', 'Focus time/reminder'], array_keys($byKind));
        self::assertSame($start->modify('-15 minutes')->format(\DATE_ATOM), $this->atom($byKind['Budget review/reminder']['fireAt']));
        self::assertSame($start->modify('+30 minutes')->format(\DATE_ATOM), $this->atom($byKind['After: Budget review/follow_up']['fireAt']));
        self::assertSame($start->modify('+2 hours -10 minutes')->format(\DATE_ATOM), $this->atom($byKind['Focus time/reminder']['fireAt']), 'Without other people the meeting rule does not apply.');

        $brief = self::string($byKind['Brief: Budget review/brief']['id']);
        $this->fire($brief);
        $delivered = array_values(array_filter($this->notifications(), static fn (array $n): bool => $n['id'] === $brief));
        self::assertSame('With Anna de Vries.', $delivered[0]['body']);
    }

    public function testRemovingAnEventDropsItsPlannedNotifications(): void
    {
        $event = $this->createEvent($this->inMinutes(2 * 24 * 60));
        self::assertCount(1, $this->notifications());

        $this->client->request('DELETE', '/api/v1/calendar-events/'.$event, server: $this->kim);
        self::assertResponseStatusCodeSame(204);
        $this->runQueuedPlanning();

        self::assertSame([], $this->notifications());
    }

    public function testTheSweepDeliversWhatAMessageMissed(): void
    {
        $this->registerDevices();
        $this->createEvent($this->inMinutes(30));
        $id = $this->onlyNotificationId();
        $this->connection()->executeStatement('UPDATE scheduled_notification SET fire_at = :past WHERE id = :id', ['past' => new \DateTimeImmutable('-5 minutes')->format('Y-m-d H:i:s'), 'id' => $id]);

        static::getContainer()->get(NotificationHandlers::class)->sweep(new SweepNotifications());

        self::assertNotNull($this->notifications()[0]['deliveredAt']);
    }

    public function testValidatesDevices(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/notification-devices', ['kind' => 'web_push', 'endpoint' => 'http://push.example.test/1', 'publicKey' => self::P256DH, 'authSecret' => self::AUTH, 'name' => 'Firefox'], $this->kim);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('POST', '/api/v1/notification-devices', ['kind' => 'web_push', 'endpoint' => self::PUSH_ENDPOINT, 'publicKey' => 'AAAA', 'authSecret' => self::AUTH, 'name' => 'Firefox'], $this->kim);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('POST', '/api/v1/notification-devices', ['kind' => 'expo', 'endpoint' => 'not-a-token', 'name' => 'Phone'], $this->kim);
        self::assertResponseStatusCodeSame(422);
    }

    public function testPrivateNotesReachTheDelta(): void
    {
        $event = $this->createEvent($this->inMinutes(60));

        $this->client->jsonRequest('PUT', '/api/v1/calendar-events/'.$event.'/note', ['text' => 'Ask about the Q4 numbers.'], $this->kim);
        self::assertResponseIsSuccessful();

        $delta = $this->getJson('/api/v1/sync/changes?since=0', $this->kim);
        self::assertIsArray($delta['eventNotes']);
        self::assertSame(['Ask about the Q4 numbers.'], array_column($delta['eventNotes'], 'text'));
    }

    /** A whole minute that many minutes from now. */
    private function inMinutes(int $minutes): \DateTimeImmutable
    {
        $moment = new \DateTimeImmutable(\sprintf('%+d minutes', $minutes), new \DateTimeZone('UTC'));

        return $moment->setTime((int) $moment->format('H'), (int) $moment->format('i'));
    }

    /**
     * Creates an event at that start (30 minutes long) and runs the planning it causes; returns its id.
     *
     * @param array<string, mixed> $overrides
     */
    private function createEvent(\DateTimeImmutable $start, array $overrides = [], int $expectStatus = 201): string
    {
        $local = $start->setTimezone(new \DateTimeZone('Europe/Amsterdam'));
        $this->client->jsonRequest('POST', '/api/v1/calendar-events', self::event($this->calendarId, [
            'summary' => 'Standup',
            'start' => ['dateTime' => $local->format('Y-m-d\TH:i:s'), 'timeZone' => 'Europe/Amsterdam'],
            'end' => ['dateTime' => $local->modify('+30 minutes')->format('Y-m-d\TH:i:s'), 'timeZone' => 'Europe/Amsterdam'],
            ...$overrides,
        ]), $this->kim);
        self::assertResponseStatusCodeSame($expectStatus);
        if (201 !== $expectStatus) {
            return '';
        }
        $id = $this->responseString('id');
        $this->runQueuedPlanning();

        return $id;
    }

    /** An event the user is invited to, as an invitation or a provider would store it. */
    private function storeInvitation(string $uid, string $summary, string $startUtc): void
    {
        $calendar = static::getContainer()->get(\App\Repository\CalendarRepository::class)->findOneOwnedBy(static::getContainer()->get(\App\Repository\UserRepository::class)->findOneBy(['email' => 'kim@example.test']) ?? self::fail('No user.'), $this->calendarId);
        self::assertNotNull($calendar);
        $ical = implode("\r\n", ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Test//EN', 'BEGIN:VEVENT', 'UID:'.$uid, 'DTSTAMP:20261019T080000Z', 'DTSTART:'.$startUtc, 'DURATION:PT30M',
            'SUMMARY:'.$summary, 'ORGANIZER:mailto:anna@finance.example', 'ATTENDEE;PARTSTAT=ACCEPTED:mailto:kim@example.test', 'END:VEVENT', 'END:VCALENDAR', '']);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->wrapInTransaction(static fn () => static::getContainer()->get(\App\Calendar\CalendarStore::class)->save(new \App\Entity\CalendarObject($calendar, $uid, ''), \App\Calendar\ICalendar\CalendarDocument::parse($ical)));
        $this->runQueuedPlanning();
    }

    private function registerDevices(): void
    {
        $this->http()->respond(self::PUSH_ENDPOINT, '', 201);
        $this->http()->respond('https://exp.host/--/api/v2/push/send', json_encode(['data' => [['status' => 'ok', 'id' => 'ticket-1']]], \JSON_THROW_ON_ERROR));
        foreach ([
            ['kind' => 'web_push', 'endpoint' => self::PUSH_ENDPOINT, 'publicKey' => self::P256DH, 'authSecret' => self::AUTH, 'name' => 'Firefox'],
            ['kind' => 'expo', 'endpoint' => self::EXPO_TOKEN, 'name' => 'Phone'],
            ['kind' => 'desktop', 'endpoint' => 'desktop-installation-1', 'name' => 'Mac'],
        ] as $device) {
            $this->client->jsonRequest('POST', '/api/v1/notification-devices', $device, $this->kim);
            self::assertResponseIsSuccessful();
        }
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function patchSettings(array $changes): void
    {
        $this->client->request('PATCH', '/api/v1/notification-settings', server: [...$this->kim, 'CONTENT_TYPE' => 'application/merge-patch+json'], content: json_encode($changes, \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
    }

    /** @return list<array<string, mixed>> */
    private function notifications(): array
    {
        /** @var list<array<string, mixed>> */
        return array_values($this->getJson('/api/v1/notifications', $this->kim));
    }

    private function onlyNotificationId(): string
    {
        $notifications = $this->notifications();
        self::assertCount(1, $notifications);

        return self::string($notifications[0]['id']);
    }

    private function fire(string $id, ?\DateTimeImmutable $at = null): void
    {
        $notification = static::getContainer()->get(EntityManagerInterface::class)->find(ScheduledNotification::class, Uuid::fromString($id));
        self::assertNotNull($notification);
        static::getContainer()->get(NotificationDeliverer::class)->fire($id, $at ?? $notification->getFireAt());
    }

    /** Runs the planning messages the requests queued, as the Messenger worker would. */
    private function runQueuedPlanning(): void
    {
        $transport = static::getContainer()->get('messenger.transport.push');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $handlers = static::getContainer()->get(NotificationHandlers::class);
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof PlanCalendarObject) {
                $handlers->plan($message);
            } elseif ($message instanceof PlanUserNotifications) {
                $handlers->planUser($message);
            }
        }
        $transport->reset();
    }

    private function http(): MockHttpRouter
    {
        return static::getContainer()->get(MockHttpRouter::class);
    }

    private function atom(mixed $value): string
    {
        return new \DateTimeImmutable(self::string($value))->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
    }
}
