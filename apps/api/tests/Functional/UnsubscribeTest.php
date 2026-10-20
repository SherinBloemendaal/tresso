<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * One-click unsubscribe end to end: the user asks from a message, the worker that leases the account makes the
 * one-click request (or the outbox sends the mailto mail), and clients see the outcome in the delta.
 */
final class UnsubscribeTest extends DatabaseTestCase
{
    use AuthenticatesUsers;
    use StoresMail;

    private const string ME = 'kim@gmail.example';
    private const string ONE_CLICK = '<https://shop.example/u?t=1>';

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
        $this->kim = $this->userHeaders('kim@example.test', ['mail:read', 'mail:send', 'settings:write']);
        $this->client->jsonRequest('POST', '/api/v1/mail-accounts', [
            'emailAddress' => self::ME,
            'displayName' => 'Work',
            'username' => self::ME,
            'password' => 'app-password-1',
            'imap' => ['host' => 'imap.company.example', 'port' => 993, 'security' => 'tls'],
            'smtp' => ['host' => 'smtp.company.example', 'port' => 465, 'security' => 'tls'],
        ], $this->kim);
        $this->accountId = $this->responseString('id');
        // Only the provider matters here: Gmail writes the topmost Authentication-Results header itself, so its DMARC
        // verdict can be trusted (see ReceivingServers); a generic IMAP server's cannot.
        $this->connection()->executeStatement("UPDATE mail_account SET provider = 'gmail' WHERE id = :id", ['id' => $this->accountId]);

        $this->worker = $this->workerHeaders('sync:lease sync:ingest');
        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => 'w1'], $this->worker);
        $this->ingest('folders', ['folders' => [['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1]]]);
        $this->ingest('messages', ['folder' => 'INBOX', 'messages' => [
            $this->message('1', 'One click', 'news@shop.example', ['list-unsubscribe' => self::ONE_CLICK.', <mailto:leave@shop.example>', 'list-unsubscribe-post' => 'List-Unsubscribe=One-Click']),
            $this->message('2', 'Mail only', 'digest@club.example', ['list-unsubscribe' => '<mailto:leave@club.example?subject=Stop>, <https://club.example/u>']),
            $this->message('3', 'Link only', 'promo@deals.example', ['list-unsubscribe' => '<https://deals.example/u>']),
            $this->message('4', 'Hello', 'anna@partner.example', []),
            $this->message('5', 'Forged', 'news@bank.example', ['list-unsubscribe' => '<https://bank.example/u>', 'list-unsubscribe-post' => 'List-Unsubscribe=One-Click', 'authentication-results' => 'mx.google.com; spf=fail; dmarc=fail (p=NONE) header.from=bank.example']),
            $this->message('6', 'Elsewhere', 'digest@club.example', ['list-unsubscribe' => '<mailto:boss@company.example>']),
        ]]);
    }

    public function testTheDeltaSaysHowAMessageCanBeUnsubscribedFrom(): void
    {
        $messages = $this->messagesBySubject($this->changes(0));

        self::assertSame('oneClick', $messages['One click']['unsubscribe']);
        self::assertSame('mailto', $messages['Mail only']['unsubscribe']);
        self::assertSame('leave@club.example', $messages['Mail only']['unsubscribeMailto'], 'Clients show where the mail goes before it is sent.');
        self::assertNull($messages['Forged']['unsubscribe'], 'A sender DMARC did not confirm is never offered.');
        self::assertNull($messages['Elsewhere']['unsubscribe'], 'A mailto address outside the sender\'s domain is never written.');
        self::assertNull($messages['Link only']['unsubscribe'], 'A link without the one-click promise is never followed.');
        self::assertNull($messages['Hello']['unsubscribe']);
    }

    public function testAOneClickRequestIsMadeByTheWorkerAndRecorded(): void
    {
        $page = $this->changes(0);
        $this->client->jsonRequest('POST', '/api/v1/unsubscriptions', ['messageId' => $this->messagesBySubject($page)['One click']['id']], $this->kim);

        self::assertResponseStatusCodeSame(202);
        $body = $this->decodedResponse();
        self::assertSame(['news@shop.example', 'oneClick', 'pending', $this->accountId], [$body['sender'], $body['method'], $body['status'], $body['mailAccountId']]);

        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => 'w1'], $this->worker);
        self::assertSame([$this->accountId], $this->decodedResponse()['outbox'] ?? null, 'The heartbeat says the account has work.');

        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/unsubscribes/claim', ['workerId' => 'w1'], $this->worker);
        self::assertResponseIsSuccessful();
        /** @var list<array{id: string, url: string}> $requests */
        $requests = $this->decodedResponse()['requests'] ?? [];
        self::assertCount(1, $requests);
        self::assertSame('https://shop.example/u?t=1', $requests[0]['url']);

        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/unsubscribes/claim', ['workerId' => 'w1'], $this->worker);
        self::assertSame([], $this->decodedResponse()['requests'], 'Claimed requests are not handed out twice.');

        $this->report($requests[0]['id'], 'done');
        self::assertResponseIsSuccessful();

        $unsubscriptions = $this->changes($page['cursor'])['unsubscriptions'];
        self::assertCount(1, $unsubscriptions);
        self::assertSame(['news@shop.example', 'done', null], [$unsubscriptions[0]['sender'], $unsubscriptions[0]['status'], $unsubscriptions[0]['error']]);
        self::assertIsString($unsubscriptions[0]['completedAt']);
    }

    public function testAskingAgainAnswersTheRequestThatIsUnderWay(): void
    {
        $messageId = $this->messagesBySubject($this->changes(0))['One click']['id'];
        $this->client->jsonRequest('POST', '/api/v1/unsubscriptions', ['messageId' => $messageId], $this->kim);
        $first = $this->responseString('id');

        $this->client->jsonRequest('POST', '/api/v1/unsubscriptions', ['messageId' => $messageId], $this->kim);

        self::assertResponseStatusCodeSame(202);
        self::assertSame($first, $this->responseString('id'));
    }

    public function testARefusedRequestFailsAndCanBeAskedAgain(): void
    {
        $messageId = $this->messagesBySubject($this->changes(0))['One click']['id'];
        $this->client->jsonRequest('POST', '/api/v1/unsubscriptions', ['messageId' => $messageId], $this->kim);
        $id = $this->responseString('id');
        $this->claim();

        $this->report($id, 'failed', "The sender's server refused the request (HTTP 404).");
        $this->client->jsonRequest('POST', '/api/v1/unsubscriptions', ['messageId' => $messageId], $this->kim);

        self::assertResponseStatusCodeSame(202);
        self::assertSame($id, $this->responseString('id'));
        self::assertSame('pending', $this->responseString('status'));
        self::assertCount(1, $this->claim(), 'Asked again, it is offered to the worker again.');
    }

    public function testATemporaryFailureIsRetriedLaterAndFailsAfterTooManyAttempts(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/unsubscriptions', ['messageId' => $this->messagesBySubject($this->changes(0))['One click']['id']], $this->kim);
        $id = $this->responseString('id');

        for ($attempt = 1; $attempt <= 6; ++$attempt) {
            $this->connection()->executeStatement('UPDATE unsubscription SET not_before = now() - interval \'1 minute\'');
            self::assertCount(1, $this->claim(), 'Attempt '.$attempt);
            $this->report($id, 'retry', 'The sender could not be reached.');
            self::assertResponseIsSuccessful();
            self::assertSame([], $this->claim(), 'Not offered again before its backoff.');
        }

        self::assertSame('failed', $this->connection()->fetchOne('SELECT status FROM unsubscription'));
    }

    public function testAReportWithoutTheClaimIsRefused(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/unsubscriptions', ['messageId' => $this->messagesBySubject($this->changes(0))['One click']['id']], $this->kim);
        $id = $this->responseString('id');

        $this->report($id, 'done');

        self::assertResponseStatusCodeSame(409);
    }

    public function testAMailtoRequestIsSentThroughTheOutboxAndDoneOnceSent(): void
    {
        $page = $this->changes(0);
        $this->client->jsonRequest('POST', '/api/v1/unsubscriptions', ['messageId' => $this->messagesBySubject($page)['Mail only']['id']], $this->kim);
        self::assertResponseStatusCodeSame(202);
        self::assertSame('mailto', $this->responseString('method'));
        self::assertSame([], $this->claim(), 'A mail is not a one-click request.');

        $after = $this->changes($page['cursor']);
        self::assertCount(1, $after['outgoingMessages']);
        /** @var array{id: string, subject: string, recipients: array{to: list<mixed>}} $outgoing */
        $outgoing = $after['outgoingMessages'][0];
        self::assertSame('unsubscribe', $outgoing['subject'], 'The header\'s own subject is not used.');
        self::assertSame([['address' => 'leave@club.example', 'name' => null]], $outgoing['recipients']['to']);

        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/outbox/claim', ['workerId' => 'w1'], $this->worker);
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/outbox/'.$outgoing['id'].'/result', ['workerId' => 'w1', 'outcome' => 'sent', 'copiedToSent' => true], $this->worker);
        self::assertResponseIsSuccessful();

        $unsubscriptions = $this->changes($after['cursor'])['unsubscriptions'];
        self::assertSame(['digest@club.example', 'done'], [$unsubscriptions[0]['sender'], $unsubscriptions[0]['status']]);
    }

    public function testCancellingTheMailtoMailFailsTheRequest(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/unsubscriptions', ['messageId' => $this->messagesBySubject($this->changes(0))['Mail only']['id']], $this->kim);
        $outgoingId = self::text($this->connection()->fetchOne('SELECT outgoing_message_id FROM unsubscription'));

        $this->client->jsonRequest('POST', '/api/v1/outgoing-messages/'.$outgoingId.'/cancel', [], $this->kim);

        self::assertResponseIsSuccessful();
        self::assertSame('failed', $this->connection()->fetchOne('SELECT status FROM unsubscription'));
    }

    public function testMessagesWithoutAOneClickWayAreRefused(): void
    {
        $messages = $this->messagesBySubject($this->changes(0));
        foreach (['Link only', 'Hello', 'Forged', 'Elsewhere'] as $subject) {
            $this->client->jsonRequest('POST', '/api/v1/unsubscriptions', ['messageId' => $messages[$subject]['id']], $this->kim);
            self::assertResponseStatusCodeSame(422, $subject);
        }
    }

    public function testSpamIsNeverAnswered(): void
    {
        $this->ingest('folders', ['folders' => [
            ['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1],
            ['remoteName' => 'Junk', 'displayName' => 'Junk', 'role' => 'spam', 'uidValidity' => 1],
        ]]);
        $this->ingest('messages', ['folder' => 'Junk', 'messages' => [
            $this->message('9', 'You won', 'prize@lottery.example', ['list-unsubscribe' => self::ONE_CLICK, 'list-unsubscribe-post' => 'List-Unsubscribe=One-Click']),
        ]]);
        $this->runClassification();

        $this->client->jsonRequest('POST', '/api/v1/unsubscriptions', ['messageId' => $this->messagesBySubject($this->changes(0))['You won']['id']], $this->kim);

        self::assertResponseStatusCodeSame(422);
    }

    public function testAnotherUsersMessageIsNotFound(): void
    {
        $messageId = $this->messagesBySubject($this->changes(0))['One click']['id'];
        $this->createUser('lisa@example.test', 'Lisa');

        $this->client->jsonRequest('POST', '/api/v1/unsubscriptions', ['messageId' => $messageId], $this->userHeaders('lisa@example.test', ['mail:send']));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAForgedSenderCannotMarkTheRealOneAsUnsubscribed(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/unsubscriptions', ['messageId' => $this->messagesBySubject($this->changes(0))['Forged']['id']], $this->kim);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, self::number($this->connection()->fetchOne('SELECT COUNT(*) FROM unsubscription')), 'Nothing recorded that could hide the real sender\'s button.');
        self::assertSame([], $this->claim(), 'And nothing for the worker to POST.');
    }

    public function testUnsubscribingNeedsTheSendScope(): void
    {
        $messageId = $this->messagesBySubject($this->changes(0))['One click']['id'];

        $this->client->jsonRequest('POST', '/api/v1/unsubscriptions', ['messageId' => $messageId], $this->userHeaders('kim@example.test', ['mail:read']));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return list<array{id: string, url: string}>
     */
    private function claim(): array
    {
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/unsubscribes/claim', ['workerId' => 'w1'], $this->worker);
        self::assertResponseIsSuccessful();

        /** @var list<array{id: string, url: string}> */
        return $this->decodedResponse()['requests'] ?? [];
    }

    private function report(string $id, string $outcome, ?string $error = null): void
    {
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/unsubscribes/'.$id.'/report', ['workerId' => 'w1', 'outcome' => $outcome, 'error' => $error], $this->worker);
    }

    private function runClassification(): void
    {
        $bus = static::getContainer()->get(MessageBusInterface::class);
        $transport = static::getContainer()->get('messenger.transport.classify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        while ([] !== ($envelopes = array_values([...$transport->get()]))) {
            $transport->reset();
            foreach ($envelopes as $envelope) {
                $bus->dispatch($envelope->with(new ReceivedStamp('classify')));
            }
        }
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function message(string $uid, string $subject, string $from, array $headers): array
    {
        $sha = hash('sha256', $uid.$subject);

        return [
            'remoteUid' => $uid,
            'rawObjectKey' => $this->rawKeyFor($this->accountId, $uid.$subject),
            'keyVersion' => 1,
            'size' => 100,
            'receivedAt' => '2026-10-19T08:00:00+00:00',
            'messageId' => '<'.$sha.'@x>',
            'subject' => $subject,
            'from' => ['address' => $from, 'name' => null],
            'to' => [['address' => self::ME]],
            'flags' => ['seen' => false, 'flagged' => false, 'answered' => false, 'draft' => false],
            'snippet' => 'Hello',
            'headers' => [] === $headers ? [] : $headers + ['authentication-results' => 'mx.google.com; dkim=pass; spf=pass; dmarc=pass (p=REJECT) header.from='.substr($from, (int) strpos($from, '@') + 1)],
        ];
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
     * @return array{cursor: int, messages: list<array<string, mixed>>, unsubscriptions: list<array<string, mixed>>, outgoingMessages: list<array<string, mixed>>}
     */
    private function changes(int $since): array
    {
        $this->client->request('GET', \sprintf('/api/v1/sync/changes?since=%d&limit=2000', $since), server: $this->userHeaders('kim@example.test', ['mail:read']));
        self::assertResponseIsSuccessful();

        /** @var array{cursor: int, messages: list<array<string, mixed>>, unsubscriptions: list<array<string, mixed>>, outgoingMessages: list<array<string, mixed>>} */
        return $this->decodedResponse();
    }

    /**
     * @param array{messages: list<array<string, mixed>>} $page
     *
     * @return array<string, array<string, mixed>>
     */
    private function messagesBySubject(array $page): array
    {
        /** @var array<string, array<string, mixed>> */
        return array_column($page['messages'], null, 'subject');
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private static function number(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
