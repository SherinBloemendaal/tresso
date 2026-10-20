<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Brief\BriefHandlers;
use App\Brief\CreateMissingBriefSettings;
use App\Brief\DailyBriefs;
use App\Brief\DeliverDailyBrief;
use App\Entity\DailyBrief;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Support\MockHttpRouter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * The daily brief end to end: settings over the API, the sweep the scheduler runs, the brief in the delta, and the
 * email digest the way the messenger worker sends it.
 */
final class DailyBriefTest extends DatabaseTestCase
{
    use AuthenticatesUsers;

    private const string ME = 'kim@company.example';
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
        $this->createUser('lisa@example.test', 'Lisa');
        $this->kim = $this->userHeaders('kim@example.test', ['mail:read', 'mail:write', 'settings:read', 'settings:write']);

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
    }

    public function testNewUsersGetTheBriefAtEightInTheMorningInTheAppOnly(): void
    {
        $this->client->request('GET', '/api/v1/daily-brief-settings', server: $this->kim);

        self::assertResponseIsSuccessful();
        $settings = $this->decodedResponse();
        self::assertTrue($settings['enabled']);
        self::assertSame('08:00', $settings['deliverAt']);
        self::assertSame('UTC', $settings['timeZone']);
        self::assertSame([], $settings['channels']);
        self::assertSame('kim@example.test', $settings['emailAddress']);
        self::assertIsString($settings['nextBriefAt']);
        self::assertSame('08:00', new \DateTimeImmutable($settings['nextBriefAt'])->format('H:i'));
    }

    public function testUsersFromBeforeBriefsGetTheDefaults(): void
    {
        $this->connection()->executeStatement('DELETE FROM daily_brief_settings WHERE user_id = :user', ['user' => $this->kimsId()]);

        $this->client->request('GET', '/api/v1/daily-brief-settings', server: $this->kim);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->decodedResponse()['enabled']);
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM daily_brief_settings WHERE user_id = :user', ['user' => $this->kimsId()]));
    }

    public function testTheHourlyBackfillGivesEveryUserWithoutSettingsTheirs(): void
    {
        $this->connection()->executeStatement('DELETE FROM daily_brief_settings');

        static::getContainer()->get(BriefHandlers::class)->createMissingSettings(new CreateMissingBriefSettings());

        self::assertSame(2, $this->rowCount('SELECT COUNT(*) FROM daily_brief_settings'));
    }

    public function testSettingsAreReplacedAndTheNextBriefIsPlannedInTheUsersZone(): void
    {
        $this->setZone('Europe/Amsterdam');

        $this->client->jsonRequest('PUT', '/api/v1/daily-brief-settings', ['enabled' => true, 'deliverAt' => '07:30', 'channels' => ['email', 'phone']], $this->kim);

        self::assertResponseIsSuccessful();
        $settings = $this->decodedResponse();
        self::assertSame(['phone', 'email'], $settings['channels']);
        self::assertSame('Europe/Amsterdam', $settings['timeZone']);
        self::assertIsString($settings['nextBriefAt']);
        $next = new \DateTimeImmutable($settings['nextBriefAt']);
        self::assertSame('07:30', $next->setTimezone(new \DateTimeZone('Europe/Amsterdam'))->format('H:i'));
        self::assertGreaterThan(new \DateTimeImmutable(), $next);
        self::assertLessThanOrEqual(new \DateTimeImmutable('+1 day'), $next);
    }

    public function testTheChannelsAreTheNotificationPreferencesOfTheDailyBrief(): void
    {
        $this->client->jsonRequest('PUT', '/api/v1/daily-brief-settings', $this->settings(['channels' => ['desktop']]), $this->kim);
        $this->client->request('GET', '/api/v1/notification-settings', server: $this->kim);

        self::assertResponseIsSuccessful();
        self::assertSame(['daily_brief' => ['desktop']], $this->decodedResponse()['channelsByKind']);

        $this->client->request('PATCH', '/api/v1/notification-settings', server: [...$this->kim, 'CONTENT_TYPE' => 'application/merge-patch+json'], content: json_encode(['channelsByKind' => ['daily_brief' => ['email']]], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/v1/daily-brief-settings', server: $this->kim);
        self::assertSame(['email'], $this->decodedResponse()['channels']);
    }

    public function testAnotherZoneMovesTheNextBrief(): void
    {
        $this->client->jsonRequest('PUT', '/api/v1/daily-brief-settings', $this->settings(['deliverAt' => '09:15']), $this->kim);

        $this->setZone('America/New_York');

        $this->client->request('GET', '/api/v1/daily-brief-settings', server: $this->kim);
        $next = $this->decodedResponse()['nextBriefAt'];
        self::assertIsString($next);
        self::assertSame('09:15', new \DateTimeImmutable($next)->setTimezone(new \DateTimeZone('America/New_York'))->format('H:i'));
    }

    public function testTurningTheBriefOffHasNoNextBrief(): void
    {
        $this->client->jsonRequest('PUT', '/api/v1/daily-brief-settings', $this->settings(['enabled' => false]), $this->kim);

        self::assertResponseIsSuccessful();
        self::assertNull($this->decodedResponse()['nextBriefAt']);
    }

    /**
     * @param array<string, mixed> $change
     */
    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsAreRefused(array $change, string $field): void
    {
        $this->client->jsonRequest('PUT', '/api/v1/daily-brief-settings', $this->settings($change), $this->kim);

        self::assertResponseStatusCodeSame(422);
        $violations = $this->decodedResponse()['violations'] ?? null;
        self::assertIsArray($violations);
        self::assertContains($field, array_column($violations, 'propertyPath'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'a time that is no time' => [['deliverAt' => '25:00'], 'deliverAt'];
        yield 'a channel there is not' => [['channels' => ['pigeon']], 'channels[0]'];
        yield 'no channels at all' => [['channels' => null], 'channels'];
    }

    public function testSettingsNeedTheSettingsScopes(): void
    {
        $mailOnly = $this->userHeaders('kim@example.test', ['mail:read']);

        $this->client->request('GET', '/api/v1/daily-brief-settings', server: $mailOnly);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheBriefHoldsTheConversationsToKnowThatAreStillInTheInbox(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [
            $this->message('1', 'Release notes', ['from' => ['address' => 'announce@lists.example'], 'headers' => ['list-id' => '<announce.lists.example>']]),
            $this->message('2', 'Build passed', ['from' => ['address' => 'ci@forge.example'], 'headers' => ['auto-submitted' => 'auto-generated']]),
            $this->message('3', 'Can you review this?'),
            $this->message('4', 'Old digest', ['from' => ['address' => 'digest@lists.example'], 'headers' => ['list-id' => '<digest.lists.example>']]),
        ]]);
        $this->runQueued('classify');
        $threads = array_column($this->changes(0)['threads'], 'id', 'subject');
        $this->archive($threads['Old digest']);

        $brief = $this->sweep();

        self::assertInstanceOf(DailyBrief::class, $brief);
        self::assertEqualsCanonicalizing([$threads['Release notes'], $threads['Build passed']], $brief->getThreadIds());
    }

    public function testTheBriefReachesClientsThroughTheDelta(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [
            $this->message('1', 'Release notes', ['from' => ['address' => 'announce@lists.example'], 'headers' => ['list-id' => '<announce.lists.example>']]),
        ]]);
        $this->runQueued('classify');
        $cursor = $this->changes(0)['cursor'];

        $brief = $this->sweep();

        self::assertInstanceOf(DailyBrief::class, $brief);
        $page = $this->changes($cursor);
        self::assertCount(1, $page['dailyBriefs']);
        $synced = $page['dailyBriefs'][0];
        self::assertSame($brief->getId()->toRfc4122(), $synced['id']);
        self::assertSame($brief->getThreadIds(), $synced['threadIds']);
        self::assertSame($brief->getCoversUntil()->format(\DATE_ATOM), $synced['coversUntil']);
        self::assertSame($brief->getCoversFrom()->format(\DATE_ATOM), $synced['coversFrom']);
    }

    public function testEachBriefCoversWhatCameSinceTheLastOne(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [
            $this->message('1', 'Release notes', ['from' => ['address' => 'announce@lists.example'], 'headers' => ['list-id' => '<announce.lists.example>']]),
        ]]);
        $this->runQueued('classify');
        $first = $this->sweep();
        self::assertInstanceOf(DailyBrief::class, $first);

        // Classified after the first brief: the time stamps are whole seconds, so this one is a second later.
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [
            $this->message('2', 'Build passed', ['from' => ['address' => 'ci@forge.example'], 'headers' => ['auto-submitted' => 'auto-generated']]),
        ]]);
        $this->runQueued('classify');
        $this->connection()->executeStatement("UPDATE message SET classified_at = classified_at + INTERVAL '2 seconds' WHERE subject = 'Build passed'");
        $second = $this->sweep(new \DateTimeImmutable('+5 seconds'));

        self::assertInstanceOf(DailyBrief::class, $second);
        $threads = array_column($this->changes(0)['threads'], 'id', 'subject');
        self::assertSame([$threads['Build passed']], $second->getThreadIds());
        self::assertEquals($first->getCoversUntil(), $second->getCoversFrom());
    }

    public function testNothingToKnowMakesNoBriefButTheNextBriefIsPlanned(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('3', 'Can you review this?')]]);
        $this->runQueued('classify');

        $brief = $this->sweep();

        self::assertNull($brief);
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM daily_brief'));
        $settings = $this->connection()->fetchAssociative('SELECT next_run_at, covered_until FROM daily_brief_settings WHERE user_id = :user', ['user' => $this->kimsId()]);
        self::assertIsArray($settings);
        self::assertNotNull($settings['covered_until']);
        self::assertGreaterThan(new \DateTimeImmutable(), new \DateTimeImmutable(self::text($settings['next_run_at'])));
    }

    public function testABriefThatIsTurnedOffIsNotMade(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [
            $this->message('1', 'Release notes', ['from' => ['address' => 'announce@lists.example'], 'headers' => ['list-id' => '<announce.lists.example>']]),
        ]]);
        $this->runQueued('classify');
        $this->client->jsonRequest('PUT', '/api/v1/daily-brief-settings', $this->settings(['enabled' => false]), $this->kim);
        $this->connection()->executeStatement("UPDATE daily_brief_settings SET next_run_at = NOW() - INTERVAL '1 minute' WHERE user_id = :user", ['user' => $this->kimsId()]);

        $handled = static::getContainer()->get(DailyBriefs::class)->sweep(new \DateTimeImmutable('+2 seconds'));

        self::assertSame(0, $handled);
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM daily_brief'));
    }

    public function testOnlyTheNewestBriefsAreKeptAndClientsDropTheRest(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $kim = $this->kim();
        $entityManager->wrapInTransaction(static function () use ($entityManager, $kim): void {
            for ($day = DailyBriefs::KEEP; $day >= 1; --$day) {
                $until = new \DateTimeImmutable(\sprintf('-%d days', $day));
                $entityManager->persist(new DailyBrief($kim, $until->modify('-1 day'), $until, [], $until));
            }
            $entityManager->flush();
        });
        $oldest = $this->connection()->fetchOne('SELECT id FROM daily_brief ORDER BY covers_until LIMIT 1');
        $cursor = $this->changes(0)['cursor'];
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [
            $this->message('1', 'Release notes', ['from' => ['address' => 'announce@lists.example'], 'headers' => ['list-id' => '<announce.lists.example>']]),
        ]]);
        $this->runQueued('classify');

        $this->sweep();

        self::assertSame(DailyBriefs::KEEP, $this->rowCount('SELECT COUNT(*) FROM daily_brief'));
        self::assertFalse($this->connection()->fetchOne('SELECT id FROM daily_brief WHERE id = :id', ['id' => $oldest]));
        self::assertContains(['type' => 'dailyBrief', 'id' => $oldest], $this->changes($cursor)['deleted']);
    }

    public function testABriefThatGoesNowhereElseQueuesNoDelivery(): void
    {
        $this->ingestKnowMail();

        self::assertInstanceOf(DailyBrief::class, $this->sweep());
        self::assertSame([], $this->takeQueued('push'));
    }

    public function testTheEmailDigestGoesOutOnceToTheNotificationAddress(): void
    {
        $this->setZone('Europe/Amsterdam');
        $this->client->request('PATCH', '/api/v1/notification-settings', server: [...$this->kim, 'CONTENT_TYPE' => 'application/merge-patch+json'], content: json_encode(['emailAddress' => self::ME], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('PUT', '/api/v1/daily-brief-settings', $this->settings(['channels' => ['email']]), $this->kim);
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [
            $this->message('1', 'Release notes', ['from' => ['address' => 'announce@lists.example', 'name' => 'Announcements'], 'headers' => ['list-id' => '<announce.lists.example>'], 'snippet' => 'Version 2 is out']),
        ]]);
        $this->runQueued('classify');

        $brief = $this->sweep();
        self::assertInstanceOf(DailyBrief::class, $brief);
        $queued = $this->takeQueued('push');
        self::assertCount(1, $queued);
        self::assertInstanceOf(DeliverDailyBrief::class, $queued[0]->getMessage());
        $this->runQueued('push', [$queued[0], $queued[0]]);

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame(self::ME, $email->getTo()[0]->getAddress());
        self::assertSame('Your daily brief: 1 to know', $email->getSubject());
        self::assertSame('auto-generated', $email->getHeaders()->getHeaderBody('Auto-Submitted'));
        self::assertStringContainsString('Release notes', (string) $email->getHtmlBody());
        self::assertStringContainsString('Version 2 is out', (string) $email->getTextBody());
        self::assertStringContainsString('/brief/'.$brief->getId()->toRfc4122(), (string) $email->getTextBody());
        self::assertNotNull($this->connection()->fetchOne('SELECT emailed_at FROM daily_brief WHERE id = :id', ['id' => $brief->getId()->toRfc4122()]));
    }

    public function testTheDigestFallsBackToTheSignInAddressWhenTheAccountIsGone(): void
    {
        $this->client->request('PATCH', '/api/v1/notification-settings', server: [...$this->kim, 'CONTENT_TYPE' => 'application/merge-patch+json'], content: json_encode(['emailAddress' => self::ME], \JSON_THROW_ON_ERROR));
        $this->client->jsonRequest('PUT', '/api/v1/daily-brief-settings', $this->settings(['channels' => ['email']]), $this->kim);
        $this->ingestKnowMail();
        self::assertInstanceOf(DailyBrief::class, $this->sweep());
        $queued = $this->takeQueued('push');
        $this->connection()->executeStatement('UPDATE mail_account SET email_address = :other WHERE id = :id', ['other' => 'renamed@company.example', 'id' => $this->accountId]);

        $this->runQueued('push', $queued);

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('kim@example.test', $email->getTo()[0]->getAddress());
    }

    public function testNothingIsSentWhenTheUserTurnedTheChannelsOffMeanwhile(): void
    {
        $this->client->jsonRequest('PUT', '/api/v1/daily-brief-settings', $this->settings(['channels' => ['email', 'phone']]), $this->kim);
        $this->registerPhone();
        $this->ingestKnowMail();
        $this->sweep();
        $queued = $this->takeQueued('push');
        $this->client->jsonRequest('PUT', '/api/v1/daily-brief-settings', $this->settings(['channels' => []]), $this->kim);

        $this->runQueued('push', $queued);

        self::assertEmailCount(0);
        self::assertSame([], $this->expoSends());
    }

    public function testThePhoneGetsAPushOnceThatOpensTheBrief(): void
    {
        $this->client->jsonRequest('PUT', '/api/v1/daily-brief-settings', $this->settings(['channels' => ['phone']]), $this->kim);
        $this->registerPhone();
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [
            $this->message('1', 'Release notes', ['from' => ['address' => 'announce@lists.example'], 'headers' => ['list-id' => '<announce.lists.example>']]),
            $this->message('2', 'Build passed', ['from' => ['address' => 'ci@forge.example'], 'headers' => ['auto-submitted' => 'auto-generated']]),
        ]]);
        $this->runQueued('classify');
        $brief = $this->sweep();
        self::assertInstanceOf(DailyBrief::class, $brief);
        $queued = $this->takeQueued('push');

        $this->runQueued('push', [$queued[0], $queued[0]]);

        $sends = $this->expoSends();
        self::assertCount(1, $sends, 'A retried delivery never notifies the phone twice.');
        $data = $sends[0]['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('daily_brief', $data['kind']);
        self::assertSame('/brief', $data['path']);
        self::assertSame($brief->getId()->toRfc4122(), $data['id']);
        self::assertSame('Daily brief: 2 to know', $data['title']);
        self::assertSame('mail', $sends[0]['channelId']);
        self::assertEmailCount(0);
    }

    public function testThePushIsSilentDuringQuietHours(): void
    {
        $this->client->jsonRequest('PUT', '/api/v1/daily-brief-settings', $this->settings(['channels' => ['phone']]), $this->kim);
        $this->client->request('PATCH', '/api/v1/notification-settings', server: [...$this->kim, 'CONTENT_TYPE' => 'application/merge-patch+json'], content: json_encode(['quietHoursEnabled' => true, 'quietDays' => [1, 2, 3, 4, 5, 6, 7], 'quietStart' => '00:00', 'quietEnd' => '23:59'], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $this->registerPhone();
        $this->ingestKnowMail();
        $this->sweep();

        $this->runQueued('push');

        $sends = $this->expoSends();
        self::assertCount(1, $sends);
        self::assertSame('mail_silent', $sends[0]['channelId']);
    }

    public function testAnotherUsersBriefNeverReachesThisUser(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [
            $this->message('1', 'Release notes', ['from' => ['address' => 'announce@lists.example'], 'headers' => ['list-id' => '<announce.lists.example>']]),
        ]]);
        $this->runQueued('classify');
        $this->sweep();
        $lisa = $this->userHeaders('lisa@example.test', ['mail:read']);

        $this->client->request('GET', '/api/v1/sync/changes?since=0', server: $lisa);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->decodedResponse()['dailyBriefs']);
    }

    /**
     * Makes Kim's brief due and runs the sweep the scheduler sends every minute, a moment after the mail was classified.
     */
    private function sweep(?\DateTimeImmutable $now = null): ?DailyBrief
    {
        $now ??= new \DateTimeImmutable('+2 seconds');
        $this->connection()->executeStatement('UPDATE daily_brief_settings SET next_run_at = :due WHERE user_id = :user', ['due' => $now->modify('-1 minute')->format('Y-m-d H:i:s'), 'user' => $this->kimsId()]);
        $before = $this->connection()->fetchOne('SELECT id FROM daily_brief ORDER BY id DESC LIMIT 1');

        self::assertSame(1, static::getContainer()->get(DailyBriefs::class)->sweep($now));

        $id = $this->connection()->fetchOne('SELECT id FROM daily_brief ORDER BY id DESC LIMIT 1');
        if (false === $id || $id === $before) {
            return null;
        }

        return static::getContainer()->get(EntityManagerInterface::class)->find(DailyBrief::class, $id);
    }

    /**
     * @param array<string, string> $params
     */
    private function rowCount(string $sql, array $params = []): int
    {
        $count = $this->connection()->fetchOne($sql, $params);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private function setZone(string $zone): void
    {
        $this->client->request('PATCH', '/api/v1/notification-settings', server: [...$this->kim, 'CONTENT_TYPE' => 'application/merge-patch+json'], content: json_encode(['timeZone' => $zone], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
    }

    private function registerPhone(): void
    {
        static::getContainer()->get(MockHttpRouter::class)->respond(self::EXPO_SEND, json_encode(['data' => [['status' => 'ok', 'id' => 'ticket-1']]], \JSON_THROW_ON_ERROR));
        $this->client->jsonRequest('POST', '/api/v1/notification-devices', ['kind' => 'expo', 'endpoint' => 'ExponentPushToken[abc123]', 'name' => 'Phone'], $this->kim);
        self::assertResponseIsSuccessful();
    }

    /**
     * @return list<array<mixed>> the messages sent to Expo
     */
    private function expoSends(): array
    {
        $sends = [];
        foreach (static::getContainer()->get(MockHttpRouter::class)->calls() as $call) {
            if (self::EXPO_SEND !== $call['url']) {
                continue;
            }
            $body = json_decode($call['body'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            $sends[] = $body;
        }

        return $sends;
    }

    private function ingestKnowMail(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [
            $this->message('1', 'Release notes', ['from' => ['address' => 'announce@lists.example'], 'headers' => ['list-id' => '<announce.lists.example>']]),
        ]]);
        $this->runQueued('classify');
    }

    private function archive(string $threadId): void
    {
        $ids = $this->connection()->fetchFirstColumn('SELECT id FROM message WHERE thread_id = :thread', ['thread' => $threadId]);
        $this->client->jsonRequest('POST', '/api/v1/message-actions', ['messageIds' => $ids, 'action' => 'archive'], $this->kim);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, mixed> $change
     *
     * @return array<string, mixed>
     */
    private function settings(array $change = []): array
    {
        return [...['enabled' => true, 'deliverAt' => '08:00', 'channels' => []], ...$change];
    }

    private function kim(): User
    {
        $kim = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'kim@example.test']);
        self::assertInstanceOf(User::class, $kim);

        return $kim;
    }

    private function kimsId(): string
    {
        return $this->kim()->getId()->toRfc4122();
    }

    /**
     * Handles what was queued on a transport (or the given envelopes), the way the messenger worker would, until nothing
     * is left.
     *
     * @param list<Envelope>|null $envelopes
     */
    private function runQueued(string $transport, ?array $envelopes = null): void
    {
        $bus = static::getContainer()->get(MessageBusInterface::class);
        $envelopes ??= $this->takeQueued($transport);
        while ([] !== $envelopes) {
            foreach ($envelopes as $envelope) {
                $bus->dispatch($envelope->with(new ReceivedStamp($transport)));
            }
            $envelopes = $this->takeQueued($transport);
        }
    }

    /**
     * @phpstan-impure
     *
     * @return list<Envelope>
     */
    private function takeQueued(string $name): array
    {
        $transport = static::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $envelopes = array_values([...$transport->get()]);
        $transport->reset();

        return $envelopes;
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
     * @return array{cursor: int, threads: list<array{id: string, subject: string}>, dailyBriefs: list<array{id: string, coversFrom: string, coversUntil: string, threadIds: list<string>, createdAt: string}>, deleted: list<array{type: string, id: string}>}
     */
    private function changes(int $since): array
    {
        $this->client->request('GET', \sprintf('/api/v1/sync/changes?since=%d&limit=2000', $since), server: $this->kim);
        self::assertResponseIsSuccessful();

        /** @var array{cursor: int, threads: list<array{id: string, subject: string}>, dailyBriefs: list<array{id: string, coversFrom: string, coversUntil: string, threadIds: list<string>, createdAt: string}>, deleted: list<array{type: string, id: string}>} */
        return $this->decodedResponse();
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
            'receivedAt' => new \DateTimeImmutable('-1 hour')->format(\DATE_ATOM),
            'messageId' => '<'.$sha.'@x>',
            'subject' => $subject,
            'from' => ['address' => 'anna@partner.example', 'name' => 'Anna'],
            'to' => [['address' => self::ME]],
            'snippet' => 'Hello',
        ], ...$override];
    }
}
