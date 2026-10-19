<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Classification\ClassifyNewMail;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Classification of ingested mail end to end: queued after the ingest commits, run the way the messenger worker runs
 * it, and seen by clients through the delta.
 */
final class ClassificationTest extends DatabaseTestCase
{
    use AuthenticatesUsers;

    private const string ME = 'kim@company.example';

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
            ['remoteName' => 'Junk', 'displayName' => 'Junk', 'role' => 'spam', 'uidValidity' => 1],
            ['remoteName' => 'Sent', 'displayName' => 'Sent', 'role' => 'sent', 'uidValidity' => 1],
        ]]);
    }

    public function testIngestQueuesClassificationAfterTheCommit(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('1', 'Budget'), $this->message('2', 'Offsite')]]);

        $queued = $this->classifyTransport()->getSent();
        self::assertCount(1, $queued, 'Queued once per transaction.');
        self::assertInstanceOf(ClassifyNewMail::class, $queued[0]->getMessage());
    }

    public function testNewMailGetsASmartInboxAndVerdictPerMessageAndConversation(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [
            $this->message('1', 'Budget'),
            $this->message('2', 'Weekly deals', ['from' => ['address' => 'newsletter@shop.example'], 'headers' => ['list-unsubscribe' => '<https://shop.example/u>']]),
            $this->message('3', 'Build failed', ['from' => ['address' => 'ci@forge.example'], 'headers' => ['auto-submitted' => 'auto-generated']]),
        ]]);
        $this->ingest('messages', ['folder' => 'Junk', 'messages' => [$this->message('1', 'You won', ['from' => ['address' => 'prize@lottery.example']])]]);
        $this->runClassification();

        $page = $this->changes(0);
        $inboxes = $this->builtinIds($page);
        $threads = array_column($page['threads'], null, 'subject');
        self::assertSame([$inboxes['todo'], 'do', 'direct'], $this->classificationOf($threads['Budget']));
        self::assertSame([$inboxes['advertise'], 'know', 'marketing_sender'], $this->classificationOf($threads['Weekly deals']));
        self::assertSame([$inboxes['notification'], 'know', 'automated'], $this->classificationOf($threads['Build failed']));
        self::assertSame([$inboxes['spam'], 'none', 'server_spam'], $this->classificationOf($threads['You won']));

        $messages = array_column($page['messages'], null, 'subject');
        self::assertSame($inboxes['todo'], $messages['Budget']['smartInboxId']);
        self::assertSame('do', $messages['Budget']['verdict']);
    }

    public function testTheUsersOwnMailIsNotClassified(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('1', 'Budget', ['messageId' => '<budget@x>'])]]);
        $this->ingest('messages', ['folder' => 'Sent', 'messages' => [
            $this->message('1', 'Re: Budget', ['messageId' => '<reply@x>', 'inReplyTo' => '<budget@x>', 'from' => ['address' => self::ME], 'to' => [['address' => 'anna@partner.example']], 'receivedAt' => '2026-10-19T09:00:00+00:00']),
            $this->message('2', 'Lunch?', ['messageId' => '<lunch@x>', 'from' => ['address' => self::ME], 'to' => [['address' => 'anna@partner.example']]]),
        ]]);
        $this->runClassification();

        $page = $this->changes(0);
        $inboxes = $this->builtinIds($page);
        $threads = array_column($page['threads'], null, 'subject');
        self::assertSame([$inboxes['todo'], 'do', 'direct'], $this->classificationOf($threads['Budget']), 'The conversation follows its newest incoming message.');
        self::assertSame([null, null, null], $this->classificationOf($threads['Lunch?']));
        $messages = array_column($page['messages'], null, 'subject');
        self::assertNull($messages['Re: Budget']['verdict']);
        self::assertEquals(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM message WHERE classified_at IS NULL'), 'Every message was looked at.');
    }

    public function testCorrectionMovesTheConversationAndIsRememberedForTheSender(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [
            $this->message('1', 'Invoice October', ['from' => ['address' => 'billing@hosting.example']]),
            $this->message('2', 'Invoice September', ['from' => ['address' => 'billing@hosting.example']]),
        ]]);
        $this->runClassification();
        $first = $this->changes(0);
        $inboxes = $this->builtinIds($first);
        $threads = array_column($first['threads'], 'id', 'subject');

        $this->client->jsonRequest('PUT', '/api/v1/threads/'.$threads['Invoice October'].'/classification', ['smartInboxId' => $inboxes['notification'], 'verdict' => 'do'], $this->kim);

        self::assertResponseIsSuccessful();
        $body = $this->decodedResponse();
        self::assertSame('user', $body['reason'] ?? null);
        self::assertSame('do', $body['verdict'] ?? null);
        self::assertIsArray($body['senderRule'] ?? null);
        self::assertSame('billing@hosting.example', $body['senderRule']['sender'] ?? null);
        $this->runClassification();

        $page = array_column($this->changes($first['cursor'])['threads'], null, 'subject');
        self::assertSame([$inboxes['notification'], 'do', 'user'], $this->classificationOf($page['Invoice October']));
        self::assertSame([$inboxes['notification'], 'do', 'sender_rule'], $this->classificationOf($page['Invoice September']), 'Recent mail from the sender moves too.');

        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('3', 'Invoice November', ['from' => ['address' => 'billing@hosting.example']])]]);
        $this->runClassification();
        $next = array_column($this->changes(0)['threads'], null, 'subject');
        self::assertSame([$inboxes['notification'], 'do', 'sender_rule'], $this->classificationOf($next['Invoice November']));
    }

    public function testCorrectedConversationsStayWhereTheUserPutThem(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('1', 'Budget')]]);
        $this->runClassification();
        $first = $this->changes(0);
        $inboxes = $this->builtinIds($first);
        $threadId = $first['threads'][0]['id'];
        $this->client->jsonRequest('PUT', '/api/v1/threads/'.$threadId.'/classification', ['smartInboxId' => $inboxes['informational']], $this->kim);
        self::assertResponseIsSuccessful();
        $this->runClassification();

        // A broad include list elsewhere does not take the conversation away.
        $this->client->request('PATCH', '/api/v1/smart-inboxes/'.$inboxes['todo'], server: $this->kim + ['CONTENT_TYPE' => 'application/merge-patch+json'], content: '{"includeSenders":["@partner.example"]}');
        self::assertResponseIsSuccessful();
        $this->runClassification();

        $thread = $this->changes(0)['threads'][0];
        self::assertSame([$inboxes['informational'], 'know', 'user'], $this->classificationOf($thread));
    }

    public function testCorrectionTakesTheSenderOffTheExcludeList(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('1', 'Budget')]]);
        $this->runClassification();
        $first = $this->changes(0);
        $inboxes = $this->builtinIds($first);
        $this->client->request('PATCH', '/api/v1/smart-inboxes/'.$inboxes['informational'], server: $this->kim + ['CONTENT_TYPE' => 'application/merge-patch+json'], content: '{"excludeSenders":["anna@partner.example","@other.example"]}');
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('PUT', '/api/v1/threads/'.$first['threads'][0]['id'].'/classification', ['smartInboxId' => $inboxes['informational']], $this->kim);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/v1/smart-inboxes/'.$inboxes['informational'], server: $this->kim);
        self::assertSame(['@other.example'], $this->decodedResponse()['excludeSenders'] ?? null);
    }

    public function testDeletingASmartInboxReleasesItsConversations(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/smart-inboxes', ['name' => 'Partners', 'includeSenders' => ['@partner.example'], 'verdict' => 'delegate'], $this->kim);
        $partners = $this->responseString('id');
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('1', 'Budget')]]);
        $this->runClassification();
        $first = $this->changes(0);
        self::assertSame([$partners, 'delegate', 'include_list'], $this->classificationOf($first['threads'][0]));

        $this->client->request('DELETE', '/api/v1/smart-inboxes/'.$partners, server: $this->kim);
        self::assertResponseStatusCodeSame(204);
        $queued = $this->takeQueued();
        $released = $this->changes($first['cursor']);
        self::assertContains(['type' => 'smartInbox', 'id' => $partners], $released['deleted']);
        self::assertSame([null, null, null], $this->classificationOf($released['threads'][0]), 'Clients see the conversation leave.');
        self::assertNull($released['messages'][0]['smartInboxId']);

        $this->runClassification($queued);
        $inboxes = $this->builtinIds($first);
        self::assertSame([$inboxes['todo'], 'do', 'direct'], $this->classificationOf($this->changes($released['cursor'])['threads'][0]));
    }

    public function testSenderRulesCanBeListedAndForgotten(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('1', 'Budget'), $this->message('2', 'Budget again', ['messageId' => '<again@x>'])]]);
        $this->runClassification();
        $first = $this->changes(0);
        $inboxes = $this->builtinIds($first);
        $threads = array_column($first['threads'], 'id', 'subject');
        $this->client->jsonRequest('PUT', '/api/v1/threads/'.$threads['Budget'].'/classification', ['smartInboxId' => $inboxes['informational']], $this->kim);
        $this->runClassification();

        $this->client->request('GET', '/api/v1/sender-rules', server: $this->kim);
        /** @var list<array{id: string, sender: string, smartInboxId: string, verdict: ?string, updatedAt: string}> $rules */
        $rules = $this->decodedResponse();
        self::assertCount(1, $rules);
        self::assertSame('anna@partner.example', $rules[0]['sender']);
        self::assertSame($inboxes['informational'], $rules[0]['smartInboxId']);
        self::assertNull($rules[0]['verdict']);

        $this->client->request('DELETE', '/api/v1/sender-rules/'.$rules[0]['id'], server: $this->kim);
        self::assertResponseStatusCodeSame(204);
        $this->runClassification();

        $threads = array_column($this->changes(0)['threads'], null, 'subject');
        self::assertSame('direct', $threads['Budget again']['classificationReason'], 'Without the rule, the rules decide again.');
        self::assertSame('user', $threads['Budget']['classificationReason']);
    }

    public function testCorrectionsStayWithinTheUsersOwnData(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('1', 'Budget')]]);
        $this->runClassification();
        $page = $this->changes(0);
        $threadId = $page['threads'][0]['id'];
        $inboxes = $this->builtinIds($page);

        $lisa = $this->userHeaders('lisa@example.test', ['mail:write', 'settings:read']);
        $this->client->jsonRequest('PUT', '/api/v1/threads/'.$threadId.'/classification', ['smartInboxId' => $inboxes['spam']], $lisa);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/v1/smart-inboxes', server: $lisa);
        $lisasTodo = array_column($this->decodedResponse(), 'id', 'builtin')['todo'];
        $this->client->jsonRequest('PUT', '/api/v1/threads/'.$threadId.'/classification', ['smartInboxId' => $lisasTodo], $this->kim);
        self::assertResponseStatusCodeSame(422);
    }

    public function testSmartInboxesFromBeforeTheSyncReachClientsOnceMailIsClassified(): void
    {
        $this->connection()->executeStatement("UPDATE category SET change_seq = 0 FROM app_user u WHERE u.id = category.owner_id AND u.email = 'kim@example.test'");
        self::assertSame([], $this->changes(0)['smartInboxes']);

        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('1', 'Budget')]]);
        $this->runClassification();

        self::assertCount(5, $this->changes(0)['smartInboxes']);
    }

    public function testCommandClassifiesAgain(): void
    {
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [$this->message('1', 'Budget')]]);
        $this->classifyTransport()->reset();

        $tester = new CommandTester(new Application($this->client->getKernel())->find('app:classification:rerun'));
        $tester->execute(['--user' => ['kim@example.test'], '--days' => '0']);

        $tester->assertCommandIsSuccessful();
        self::assertSame('direct', $this->changes(0)['threads'][0]['classificationReason']);
    }

    /**
     * Handles what the last request queued (or the given envelopes), the way the messenger worker would. The test
     * client empties the in-memory transport before every request, so this runs right after the request that queued.
     *
     * @param list<Envelope>|null $envelopes
     */
    private function runClassification(?array $envelopes = null): void
    {
        $bus = static::getContainer()->get(MessageBusInterface::class);
        $envelopes ??= $this->takeQueued();
        while ([] !== $envelopes) {
            foreach ($envelopes as $envelope) {
                $bus->dispatch($envelope->with(new ReceivedStamp('classify')));
            }
            $envelopes = $this->takeQueued();
        }
    }

    /** @return list<Envelope> */
    private function takeQueued(): array
    {
        $transport = $this->classifyTransport();
        $envelopes = array_values([...$transport->get()]);
        $transport->reset();

        return $envelopes;
    }

    private function classifyTransport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.classify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * @param array{smartInboxes: list<array<string, mixed>>} $page
     *
     * @return array<string, string> built-in smart inbox ids by kind
     */
    private function builtinIds(array $page): array
    {
        $ids = [];
        foreach ($page['smartInboxes'] as $smartInbox) {
            if (\is_string($smartInbox['builtin']) && \is_string($smartInbox['id'])) {
                $ids[$smartInbox['builtin']] = $smartInbox['id'];
            }
        }

        return $ids;
    }

    /**
     * @param array{smartInboxId: ?string, verdict: ?string, classificationReason: ?string} $thread
     *
     * @return array{?string, ?string, ?string}
     */
    private function classificationOf(array $thread): array
    {
        return [$thread['smartInboxId'], $thread['verdict'], $thread['classificationReason']];
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
     * @return array{cursor: int, smartInboxes: list<array<string, mixed>>, threads: list<array{id: string, subject: string, smartInboxId: ?string, verdict: ?string, classificationReason: ?string}>, messages: list<array{id: string, subject: string, smartInboxId: ?string, verdict: ?string}>, deleted: list<array{type: string, id: string}>}
     */
    private function changes(int $since): array
    {
        $this->client->request('GET', \sprintf('/api/v1/sync/changes?since=%d&limit=2000', $since), server: $this->kim);
        self::assertResponseIsSuccessful();

        /** @var array{cursor: int, smartInboxes: list<array<string, mixed>>, threads: list<array{id: string, subject: string, smartInboxId: ?string, verdict: ?string, classificationReason: ?string}>, messages: list<array{id: string, subject: string, smartInboxId: ?string, verdict: ?string}>, deleted: list<array{type: string, id: string}>} */
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
            'receivedAt' => '2026-10-19T08:00:00+00:00',
            'messageId' => '<'.$sha.'@x>',
            'subject' => $subject,
            'from' => ['address' => 'anna@partner.example', 'name' => 'Anna'],
            'to' => [['address' => self::ME]],
            'snippet' => 'Hello',
        ], ...$override];
    }
}
