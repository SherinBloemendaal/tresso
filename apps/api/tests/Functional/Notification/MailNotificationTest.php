<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Classification\MailClassification;
use App\Entity\Category;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\Verdict;
use App\Notification\MailNotifier;
use App\Notification\NotificationHandlers;
use App\Notification\PlanCalendarObject;
use App\Notification\SendMailNotification;
use App\Repository\UserRepository;
use App\Tests\Functional\AuthenticatesUsers;
use App\Tests\Functional\Calendar\CalendarTestHelpers;
use App\Tests\Functional\DatabaseTestCase;
use App\Tests\Support\MockHttpRouter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Push for new mail end to end: ingested, classified `do`, and delivered once to the phone and the browser, with the
 * conversation to open; shown by the desktop app from the delta.
 */
final class MailNotificationTest extends DatabaseTestCase
{
    use AuthenticatesUsers;
    use CalendarTestHelpers;

    private const string ME = 'kim@company.example';
    private const string PUSH_ENDPOINT = 'https://push.example.test/subscriptions/1';
    private const string P256DH = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    private const string AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';
    private const string EXPO_SEND = 'https://exp.host/--/api/v2/push/send';

    private string $accountId;

    /** @var array<string, string> */
    private array $worker;

    /** @var array<string, string> */
    private array $kim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapOAuthClients();
        $this->createUser('kim@example.test', 'Kim');
        $this->kim = $this->userHeaders('kim@example.test', ['mail:read', 'mail:write', 'calendar:read', 'calendar:write', 'settings:read', 'settings:write']);

        $this->client->jsonRequest('POST', '/api/v1/mail-accounts', [
            'emailAddress' => self::ME,
            'displayName' => 'Work',
            'username' => self::ME,
            'password' => 'app-password-1',
            'imap' => ['host' => 'imap.company.example', 'port' => 993, 'security' => 'tls'],
            'smtp' => ['host' => 'smtp.company.example', 'port' => 465, 'security' => 'tls'],
        ], $this->kim);
        $this->accountId = $this->responseString('id');

        $this->worker = $this->workerHeaders('sync:lease sync:ingest');
        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => 'w1'], $this->worker);
        $this->ingest('folders', ['folders' => [
            ['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1],
            ['remoteName' => 'Archive', 'displayName' => 'Archive', 'role' => 'archive', 'uidValidity' => 1],
        ]]);

        $this->http()->respond(self::PUSH_ENDPOINT, '', 201);
        $this->http()->respond(self::EXPO_SEND, json_encode(['data' => [['status' => 'ok', 'id' => 'ticket-1']]], \JSON_THROW_ON_ERROR));
        foreach ([
            ['kind' => 'web_push', 'endpoint' => self::PUSH_ENDPOINT, 'publicKey' => self::P256DH, 'authSecret' => self::AUTH, 'name' => 'Firefox'],
            ['kind' => 'expo', 'endpoint' => 'ExponentPushToken[abc123]', 'name' => 'Phone'],
        ] as $device) {
            $this->client->jsonRequest('POST', '/api/v1/notification-devices', $device, $this->kim);
            self::assertResponseIsSuccessful();
        }
    }

    public function testNewMailThatNeedsTheUserReachesThePhoneAndBrowserOnceWithItsConversation(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('1', 'Budget review', ['snippet' => 'Can you check the numbers?'])]]);
        $this->classifyAndSend();

        $page = $this->changes();
        $thread = $page['threads'][0];
        self::assertSame('do', $thread['verdict']);
        $expo = $this->expoBodies();
        self::assertCount(1, $expo);
        self::assertSame('Anna de Vries', $expo[0]['title']);
        self::assertSame("Budget review\nCan you check the numbers?", $expo[0]['body']);
        self::assertSame('mail', $expo[0]['channelId']);
        self::assertSame('default', $expo[0]['sound']);
        self::assertSame('mail', $expo[0]['data']['kind']);
        self::assertSame('/thread/'.$thread['id'], $expo[0]['data']['path'], 'A tap opens the conversation.');
        self::assertSame($thread['id'], $expo[0]['data']['threadId']);
        self::assertCount(1, $this->http()->requestsTo(self::PUSH_ENDPOINT), 'The browser gets it by web push.');

        $notifications = $page['mailNotifications'];
        self::assertCount(1, $notifications, 'The desktop app sees it in the delta.');
        self::assertSame($thread['id'], $notifications[0]['threadId']);
        self::assertSame(['phone', 'desktop'], $notifications[0]['channels']);
        self::assertFalse($notifications[0]['silent']);
        self::assertNotNull($notifications[0]['deliveredAt']);
    }

    public function testAMessageNotifiesOnlyOnce(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('1', 'Budget review')]]);
        $this->classifyAndSend();

        // Judged otherwise and then `do` again (a rule changed), delivered again by a retried message or the sweep.
        $this->connection()->executeStatement("UPDATE message SET verdict = 'know'");
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        static::getContainer()->get(MailClassification::class)->reclassify($this->kimsId(), null);
        $this->sendQueued();
        $this->notifier()->deliver($this->onlyNotificationId(), new \DateTimeImmutable());
        $this->notifier()->sweep(new \DateTimeImmutable('+1 minute'));

        self::assertCount(1, $this->expoBodies());
        self::assertCount(1, $this->http()->requestsTo(self::PUSH_ENDPOINT));
        self::assertEquals(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM mail_notification'));
    }

    public function testMailThatDoesNotNeedTheUserNowNeverNotifies(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [
            $this->message('1', 'Weekly deals', ['from' => ['address' => 'newsletter@shop.example'], 'headers' => ['list-unsubscribe' => '<https://shop.example/u>']]),
            $this->message('2', 'Already read', ['flags' => ['seen' => true, 'flagged' => false, 'answered' => false, 'draft' => false]]),
            $this->message('3', 'From last year', ['receivedAt' => '2025-10-19T08:00:00+00:00']),
        ]]);
        $this->ingest('messages', ['folder' => 'Archive', 'messages' => [$this->message('1', 'Filed away')]]);
        $this->classifyAndSend();

        $verdicts = array_column($this->changes()['threads'], 'verdict', 'subject');
        self::assertSame(['know', 'do', 'do', 'do'], [$verdicts['Weekly deals'], $verdicts['Already read'], $verdicts['From last year'], $verdicts['Filed away']]);
        self::assertEquals(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM mail_notification'), 'Know mail, read mail, old mail (an account being fetched) and mail outside the inbox.');
        self::assertSame([], $this->expoBodies());
    }

    public function testMailReadBeforeItsDeliveryIsDropped(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('1', 'Budget review')]]);
        $this->runClassification();
        // Read on another device before the push queue got to it.
        $this->ingest('changes', ['folder' => 'INBOX', 'flags' => [['remoteUid' => '1', 'flags' => ['seen' => true, 'flagged' => false, 'answered' => false, 'draft' => false]]]]);
        $this->notifier()->deliver($this->onlyNotificationId(), new \DateTimeImmutable());

        self::assertSame([], $this->expoBodies());
        self::assertSame([], $this->changes()['mailNotifications']);
        self::assertEquals(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM mail_notification WHERE dropped_at IS NOT NULL AND delivered_at IS NULL'));
    }

    public function testTheUserChoosesTheChannelsForMail(): void
    {
        $this->patchSettings(['channelsByKind' => ['mail' => ['phone']]]);
        self::assertSame(['mail' => ['phone']], $this->getJson('/api/v1/notification-settings', $this->kim)['channelsByKind']);
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('1', 'Budget review')]]);
        $this->classifyAndSend();

        self::assertCount(1, $this->expoBodies());
        self::assertSame([], $this->http()->requestsTo(self::PUSH_ENDPOINT));
        self::assertSame(['phone'], $this->changes()['mailNotifications'][0]['channels'], 'The desktop app shows only what was sent to the desktop.');

        $this->patchSettings(['channelsByKind' => ['mail' => []]]);
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('2', 'Offsite plans')]]);
        $this->classifyAndSend();

        self::assertCount(1, $this->expoBodies(), 'No channel: no notification for mail.');
    }

    public function testQuietHoursDeliverMailSilently(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->patchSettings(['timeZone' => 'UTC', 'quietHoursEnabled' => true, 'quietDays' => [1, 2, 3, 4, 5, 6, 7], 'quietStart' => $now->modify('-1 hour')->format('H:i'), 'quietEnd' => $now->modify('+1 hour')->format('H:i')]);
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('1', 'Budget review')]]);
        $this->classifyAndSend();

        $expo = $this->expoBodies();
        self::assertCount(1, $expo);
        self::assertNull($expo[0]['sound']);
        self::assertSame('mail_silent', $expo[0]['channelId'], 'On Android the silent channel decides.');
        self::assertTrue($this->changes()['mailNotifications'][0]['silent']);
    }

    public function testAMeetingHoldsMailUntilItEnds(): void
    {
        $start = $this->minute(-10);
        $this->createEvent($start, ['summary' => 'Budget meeting', 'attendees' => [['address' => 'anna@partner.example', 'name' => 'Anna de Vries']], 'organizerMailAccountId' => $this->accountId]);
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('1', 'Budget review')]]);
        $this->classifyAndSend();

        self::assertSame([], $this->expoBodies(), 'Not while the user is in the meeting.');
        $end = $start->modify('+30 minutes');
        self::assertSame($end->format('Y-m-d H:i:s'), $this->connection()->fetchOne('SELECT fire_at FROM mail_notification'));
        $delayed = $this->pushTransport()->getSent();
        self::assertCount(1, $delayed);
        self::assertInstanceOf(SendMailNotification::class, $delayed[0]->getMessage());
        $delay = $delayed[0]->last(DelayStamp::class)?->getDelay() ?? 0;
        self::assertEqualsWithDelta(($end->getTimestamp() - time()) * 1000, $delay, 5000, 'It comes back when the meeting ends.');

        $this->notifier()->deliver($this->onlyNotificationId(), $end);

        self::assertCount(1, $this->expoBodies());
    }

    public function testAFocusBlockHoldsMailUntilItEnds(): void
    {
        $start = $this->minute(-10);
        $this->createEvent($start, ['summary' => 'Focus: budget']);
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('1', 'Budget review')]]);
        $this->classifyAndSend();

        self::assertSame([], $this->expoBodies());
        self::assertSame($start->modify('+30 minutes')->format('Y-m-d H:i:s'), $this->connection()->fetchOne('SELECT fire_at FROM mail_notification'));
    }

    public function testWithoutTheFocusHoldMailComesSilentlyWhileTheUserIsBusy(): void
    {
        $this->patchSettings(['holdDuringFocus' => false, 'focusDuringBusy' => true]);
        $this->createEvent($this->minute(-10), ['summary' => 'Focus: budget']);
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('1', 'Budget review')]]);
        $this->classifyAndSend();

        $expo = $this->expoBodies();
        self::assertCount(1, $expo);
        self::assertSame('mail_silent', $expo[0]['channelId']);
    }

    public function testAFailedSendIsTriedAgainBySweep(): void
    {
        $this->http()->respondOnce(self::PUSH_ENDPOINT, '', 500);
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('1', 'Budget review')]]);
        $this->classifyAndSend();
        self::assertSame(['failed', 'sent'], $this->connection()->fetchFirstColumn("SELECT status FROM notification_delivery WHERE delivery_key LIKE 'mail:%' ORDER BY status"));

        $this->connection()->executeStatement("UPDATE notification_delivery SET updated_at = updated_at - INTERVAL '5 minutes'");
        $this->notifier()->sweep(new \DateTimeImmutable());

        self::assertCount(2, $this->http()->requestsTo(self::PUSH_ENDPOINT));
        self::assertCount(1, $this->expoBodies(), 'What went out is never sent again.');
    }

    public function testASweepDeliversWhatALostMessageMissed(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('1', 'Budget review')]]);
        $this->runClassification();
        $this->pushTransport()->reset();

        $this->notifier()->sweep(new \DateTimeImmutable());

        self::assertCount(1, $this->expoBodies());
    }

    public function testMailToHandOverSaysSo(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('1', 'Weekly deals', ['from' => ['address' => 'newsletter@shop.example', 'name' => ''], 'headers' => ['list-unsubscribe' => '<https://shop.example/u>'], 'snippet' => ''])]]);
        $this->runClassification();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $message = $entityManager->getRepository(Message::class)->findOneBy(['subject' => 'Weekly deals']) ?? self::fail('No message.');
        $category = $message->getCategory() ?? self::fail('Not classified.');
        self::assertInstanceOf(Category::class, $category);
        // As an AI classifier (Tresso Cloud) judges it: to hand over.
        $entityManager->wrapInTransaction(function () use ($message, $category): void {
            $message->classify($category, Verdict::Delegate, new \DateTimeImmutable());
            $this->notifier()->consider($this->kim(), [[$message, Verdict::Know]], new \DateTimeImmutable());
        });
        $this->sendQueued();

        $expo = $this->expoBodies();
        self::assertCount(1, $expo);
        self::assertSame('newsletter@shop.example', $expo[0]['title'], 'Without a name, the address.');
        self::assertSame('To hand over: Weekly deals', $expo[0]['body']);
    }

    private function classifyAndSend(): void
    {
        $this->runClassification();
        $this->sendQueued();
    }

    /** Runs the classification queued by the ingest, the way the classify worker does. */
    private function runClassification(): void
    {
        $transport = static::getContainer()->get('messenger.transport.classify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $bus = static::getContainer()->get(MessageBusInterface::class);
        while ([] !== ($envelopes = array_values([...$transport->get()]))) {
            $transport->reset();
            foreach ($envelopes as $envelope) {
                $bus->dispatch($envelope->with(new ReceivedStamp('classify')));
            }
        }
    }

    /** Delivers the mail notifications classification queued, the way the push worker does (delays aside). */
    private function sendQueued(): void
    {
        $transport = $this->pushTransport();
        $envelopes = array_values(array_filter([...$transport->get()], static fn ($envelope): bool => $envelope->getMessage() instanceof SendMailNotification));
        $transport->reset();
        foreach ($envelopes as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(SendMailNotification::class, $message);
            $this->notifier()->onSend($message);
        }
    }

    private function pushTransport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.push');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * Creates an event at that start (30 minutes long) and runs the planning it causes.
     *
     * @param array<string, mixed> $overrides
     */
    private function createEvent(\DateTimeImmutable $start, array $overrides): void
    {
        $local = $start->setTimezone(new \DateTimeZone('Europe/Amsterdam'));
        $this->client->jsonRequest('POST', '/api/v1/calendar-events', self::event($this->localCalendarId($this->kim), [
            'start' => ['dateTime' => $local->format('Y-m-d\TH:i:s'), 'timeZone' => 'Europe/Amsterdam'],
            'end' => ['dateTime' => $local->modify('+30 minutes')->format('Y-m-d\TH:i:s'), 'timeZone' => 'Europe/Amsterdam'],
            ...$overrides,
        ]), $this->kim);
        self::assertResponseStatusCodeSame(201);
        $transport = $this->pushTransport();
        $handlers = static::getContainer()->get(NotificationHandlers::class);
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof PlanCalendarObject) {
                $handlers->plan($message);
            }
        }
        $transport->reset();
    }

    private function minute(int $offset): \DateTimeImmutable
    {
        $moment = new \DateTimeImmutable(\sprintf('%+d minutes', $offset), new \DateTimeZone('UTC'));

        return $moment->setTime((int) $moment->format('H'), (int) $moment->format('i'));
    }

    private function onlyNotificationId(): string
    {
        $id = $this->connection()->fetchOne('SELECT id FROM mail_notification');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return list<array{title: string, body: string, sound: ?string, channelId: string, data: array<string, mixed>}>
     */
    private function expoBodies(): array
    {
        $bodies = [];
        foreach ($this->http()->requestsTo(self::EXPO_SEND) as $call) {
            /** @var array{title: string, body: string, sound: ?string, channelId: string, data: array<string, mixed>} $body */
            $body = json_decode($call['body'], true, flags: \JSON_THROW_ON_ERROR);
            $bodies[] = $body;
        }

        return $bodies;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function patchSettings(array $changes): void
    {
        $this->client->request('PATCH', '/api/v1/notification-settings', server: [...$this->kim, 'CONTENT_TYPE' => 'application/merge-patch+json'], content: json_encode($changes, \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function ingest(string $action, array $payload): void
    {
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/'.$action, ['workerId' => 'w1'] + $payload, $this->worker);
        self::assertResponseIsSuccessful();
    }

    /**
     * @return array{threads: list<array{id: string, subject: string, verdict: ?string}>, mailNotifications: list<array<string, mixed>>}
     */
    private function changes(): array
    {
        /** @var array{threads: list<array{id: string, subject: string, verdict: ?string}>, mailNotifications: list<array<string, mixed>>} */
        return $this->getJson('/api/v1/sync/changes?since=0&limit=2000', $this->kim);
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function message(string $uid, string $subject, array $override = []): array
    {
        $sha = hash('sha256', $uid.$subject);

        return [...[
            'remoteUid' => $uid,
            'rawObjectKey' => 'raw/'.substr($sha, 0, 2).'/'.$sha,
            'size' => 100,
            'receivedAt' => new \DateTimeImmutable('-2 minutes')->format(\DATE_ATOM),
            'messageId' => '<'.$sha.'@x>',
            'subject' => $subject,
            'from' => ['address' => 'anna@partner.example', 'name' => 'Anna de Vries'],
            'to' => [['address' => self::ME]],
            'snippet' => '',
            'flags' => ['seen' => false, 'flagged' => false, 'answered' => false, 'draft' => false],
        ], ...$override];
    }

    private function notifier(): MailNotifier
    {
        return static::getContainer()->get(MailNotifier::class);
    }

    private function kim(): User
    {
        return static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'kim@example.test']) ?? self::fail('No user.');
    }

    private function kimsId(): Uuid
    {
        return $this->kim()->getId();
    }

    private function http(): MockHttpRouter
    {
        return static::getContainer()->get(MockHttpRouter::class);
    }
}
