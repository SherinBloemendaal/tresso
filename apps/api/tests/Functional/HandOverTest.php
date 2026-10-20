<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\OutgoingMessage;
use App\HandOver\HandOverDue;
use App\HandOver\HandOverDueHandler;
use App\HandOver\NotifyOverdueHandOver;
use App\HandOver\NotifyOverdueHandOverHandler;
use App\Tests\Support\MockHttpRouter;
use App\Tests\Support\InMemoryObjectStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class HandOverTest extends DatabaseTestCase
{
    use AuthenticatesUsers;

    private const string EXPO_SEND = 'https://exp.host/--/api/v2/push/send';

    private string $accountId;

    /** @var array<string, string> */
    private array $kim;

    /** @var array<string, string> */
    private array $worker;

    private string $originalId;

    private string $forwardId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapOAuthClients();
        $this->createUser('kim@example.test', 'Kim');
        $this->createUser('lisa@example.test', 'Lisa');
        $this->kim = $this->userHeaders('kim@example.test', ['mail:read', 'mail:write', 'mail:send', 'settings:write']);

        $this->client->jsonRequest('POST', '/api/v1/mail-accounts', [
            'emailAddress' => 'kim@company.example',
            'displayName' => 'Kim Visser',
            'username' => 'kim@company.example',
            'password' => 'app-password-1',
            'imap' => ['host' => 'imap.company.example', 'port' => 993, 'security' => 'tls'],
            'smtp' => ['host' => 'smtp.company.example', 'port' => 465, 'security' => 'tls'],
        ], $this->kim);
        $this->accountId = $this->responseString('id');

        $this->worker = $this->workerHeaders('sync:lease sync:ingest');
        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => 'w1'], $this->worker);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/folders', ['workerId' => 'w1', 'folders' => [
            ['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1],
        ]], $this->worker);
        self::assertResponseIsSuccessful();

        $this->ingest('1', '<invoice-1@duinzicht.example>', null, 'Invoice question', 'reservations@duinzicht.example', '2026-10-19T08:00:00+00:00');
        $this->originalId = $this->messageIdBySubject('Invoice question');

        $this->client->jsonRequest('POST', '/api/v1/outgoing-messages', [
            'mailAccountId' => $this->accountId,
            'to' => [['address' => 'Lisa@Company.example', 'name' => 'Lisa Bakker']],
            'subject' => 'Fwd: Invoice question',
            'html' => '<p>Can you check the double charge?</p>',
            'inReplyToMessageId' => $this->originalId,
        ], $this->kim);
        self::assertResponseStatusCodeSame(201);
        $this->forwardId = $this->responseString('id');
        $this->asyncTransport()->reset();
    }

    public function testHandsOverAForwardedConversation(): void
    {
        $dueAt = new \DateTimeImmutable('+3 days')->setTime(17, 0);
        $handOver = $this->handOver(['dueAt' => $dueAt->format(\DATE_ATOM)]);

        self::assertSame($this->accountId, $handOver['mailAccountId']);
        self::assertSame($this->originalId, $handOver['messageId']);
        self::assertSame('Invoice question', $handOver['subject']);
        self::assertSame(['address' => 'lisa@company.example', 'name' => 'Lisa Bakker'], $handOver['delegate']);
        self::assertSame('Can you check the double charge?', $handOver['note']);
        self::assertSame('open', $handOver['state']);
        self::assertSame($this->forwardId, $handOver['forwardId']);
        self::assertNull($handOver['overdueAt']);
        self::assertNull($handOver['closedAt']);
        self::assertSame($dueAt->format(\DATE_ATOM), new \DateTimeImmutable(\is_string($handOver['dueAt']) ? $handOver['dueAt'] : '')->format(\DATE_ATOM));
    }

    public function testSchedulesTheDueCheckAtTheDueDate(): void
    {
        $handOver = $this->handOver(['dueAt' => new \DateTimeImmutable('+2 hours')->format(\DATE_ATOM)]);

        $checks = $this->dueChecks();
        self::assertCount(1, $checks);
        self::assertSame($handOver['id'], $checks[0][0]->handOverId);
        self::assertNotNull($checks[0][1]);
        self::assertEqualsWithDelta(7_200_000, $checks[0][1], 5_000);
    }

    public function testRejectsADelegateTheForwardDidNotGoTo(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/hand-overs', $this->payload(['delegate' => ['address' => 'pieter@company.example']]), $this->kim);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['delegate.address'], $this->violationPaths());
    }

    public function testRejectsADueDateInThePast(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/hand-overs', $this->payload(['dueAt' => '2020-01-01T00:00:00+00:00']), $this->kim);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['dueAt'], $this->violationPaths());
    }

    public function testRejectsAForwardThatWasCancelled(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/outgoing-messages/'.$this->forwardId.'/cancel', [], $this->kim);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('POST', '/api/v1/hand-overs', $this->payload(), $this->kim);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['forwardId'], $this->violationPaths());
    }

    public function testOtherUsersCannotHandOverTheMessage(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/hand-overs', $this->payload(), $this->userHeaders('lisa@example.test', ['mail:read', 'mail:write']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['messageId'], $this->violationPaths());
    }

    public function testOtherUsersCannotSeeOrChangeIt(): void
    {
        $id = $this->handOverId();
        $lisa = $this->userHeaders('lisa@example.test', ['mail:read', 'mail:write', 'mail:send']);

        $this->client->request('GET', '/api/v1/hand-overs/'.$id, server: $lisa);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('PATCH', '/api/v1/hand-overs/'.$id, server: $lisa + ['CONTENT_TYPE' => 'application/merge-patch+json'], content: json_encode(['done' => true], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/v1/hand-overs', server: $lisa);
        self::assertSame([], $this->decodedResponse());
    }

    public function testListsOpenOnesFirst(): void
    {
        $done = $this->handOverId();
        $this->patch($done, ['done' => true]);
        $later = $this->handOver(['dueAt' => new \DateTimeImmutable('+5 days')->format(\DATE_ATOM)])['id'];
        $sooner = $this->handOver(['dueAt' => new \DateTimeImmutable('+1 day')->format(\DATE_ATOM)])['id'];

        $this->client->request('GET', '/api/v1/hand-overs', server: $this->kim);

        self::assertResponseIsSuccessful();
        self::assertSame([$sooner, $later, $done], array_column($this->decodedResponse(), 'id'));
    }

    public function testRequiresTheWriteScope(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/hand-overs', $this->payload(), $this->userHeaders('kim@example.test', ['mail:read', 'mail:send']));

        self::assertResponseStatusCodeSame(403);
    }

    public function testClosesWhenTheDelegateReplies(): void
    {
        $id = $this->handOverId();

        $this->ingest('2', '<reply-1@company.example>', '<invoice-1@duinzicht.example>', 'Re: Fwd: Invoice question', 'lisa@company.example', new \DateTimeImmutable('+1 minute')->format(\DATE_ATOM));

        $handOver = $this->get($id);
        self::assertSame('replied', $handOver['state']);
        self::assertSame($this->messageIdBySubject('Re: Fwd: Invoice question'), $handOver['replyMessageId']);
        self::assertNotNull($handOver['closedAt']);
    }

    public function testStaysOpenForMailFromSomeoneElse(): void
    {
        $id = $this->handOverId();

        $this->ingest('2', '<reply-1@duinzicht.example>', '<invoice-1@duinzicht.example>', 'Re: Invoice question', 'reservations@duinzicht.example', new \DateTimeImmutable('+1 minute')->format(\DATE_ATOM));

        self::assertSame('open', $this->get($id)['state']);
    }

    public function testStaysOpenForTheDelegatesMailInAnotherConversation(): void
    {
        $id = $this->handOverId();

        $this->ingest('2', '<lunch@company.example>', null, 'Lunch on Friday?', 'lisa@company.example', new \DateTimeImmutable('+1 minute')->format(\DATE_ATOM));

        self::assertSame('open', $this->get($id)['state']);
    }

    public function testBecomesOverdueOnceAtTheDueDate(): void
    {
        $id = $this->handOverId();
        $this->connection()->executeStatement("UPDATE hand_over SET due_at = now() - interval '1 minute' WHERE id = :id", ['id' => $id]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        static::getContainer()->get(HandOverDueHandler::class)(new HandOverDue($id));
        $seq = $this->changeSeq($id);
        static::getContainer()->get(HandOverDueHandler::class)(new HandOverDue($id));
        $told = array_map(static fn (NotifyOverdueHandOver $notify): string => $notify->handOverId, $this->notifications());
        $handOver = $this->get($id);

        self::assertNotNull($handOver['overdueAt']);
        self::assertSame('open', $handOver['state']);
        self::assertSame($seq, $this->changeSeq($id), 'It is marked overdue once, so clients tell the user once.');
        self::assertSame([$id], $told, 'The user is told once.');
    }

    public function testTellsTheUserOnTheirDevicesOnce(): void
    {
        $id = $this->overdueHandOver();
        $this->registerPhone();

        $this->notifyOverdue($id);
        $this->notifyOverdue($id);

        $sends = $this->expoSends();
        self::assertCount(1, $sends, 'A retried message never notifies twice.');
        self::assertSame(['handOverId' => $id, 'id' => $id, 'kind' => 'hand_over', 'title' => 'No reply from Lisa Bakker yet', 'body' => 'Invoice question is past its due date. Nudge from Waiting on.', 'path' => '/waiting?nudge='.$id, 'silent' => false], $sends[0]['data'] ?? null);
    }

    public function testTellsAgainWhenANewDueDatePasses(): void
    {
        $id = $this->overdueHandOver();
        $this->registerPhone();
        $this->notifyOverdue($id);

        $this->patch($id, ['dueAt' => new \DateTimeImmutable('+1 day')->format(\DATE_ATOM)]);
        $this->connection()->executeStatement("UPDATE hand_over SET due_at = now() - interval '5 minutes' WHERE id = :id", ['id' => $id]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        static::getContainer()->get(HandOverDueHandler::class)(new HandOverDue($id));
        $this->notifyOverdue($id);

        self::assertCount(2, $this->expoSends());
    }

    public function testTellsNothingOnceItIsClosed(): void
    {
        $id = $this->overdueHandOver();
        $this->registerPhone();
        $this->patch($id, ['done' => true]);

        $this->notifyOverdue($id);

        self::assertSame([], $this->expoSends());
    }

    public function testFollowsTheChannelsChosenForHandOvers(): void
    {
        $id = $this->overdueHandOver();
        $this->registerPhone();
        $this->client->request('PATCH', '/api/v1/notification-settings', server: $this->settings() + ['CONTENT_TYPE' => 'application/merge-patch+json'], content: json_encode(['channelsByKind' => ['hand_over' => []]], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->notifyOverdue($id);

        self::assertSame([], $this->expoSends());
    }

    public function testIsNotOverdueBeforeTheDueDate(): void
    {
        $id = $this->handOverId();

        static::getContainer()->get(HandOverDueHandler::class)(new HandOverDue($id));

        self::assertNull($this->get($id)['overdueAt']);
    }

    public function testIsNotOverdueOnceReplied(): void
    {
        $id = $this->handOverId();
        $this->ingest('2', '<reply-1@company.example>', '<invoice-1@duinzicht.example>', 'Re: Fwd: Invoice question', 'lisa@company.example', new \DateTimeImmutable('+1 minute')->format(\DATE_ATOM));
        $this->connection()->executeStatement("UPDATE hand_over SET due_at = now() - interval '1 minute' WHERE id = :id", ['id' => $id]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        static::getContainer()->get(HandOverDueHandler::class)(new HandOverDue($id));

        self::assertNull($this->get($id)['overdueAt']);
    }

    public function testTheWorkerHeartbeatMarksOverdueHandOvers(): void
    {
        $id = $this->handOverId();
        $this->connection()->executeStatement("UPDATE hand_over SET due_at = now() - interval '1 minute' WHERE id = :id", ['id' => $id]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => 'w1'], $this->worker);
        self::assertResponseIsSuccessful();

        self::assertNotNull($this->get($id)['overdueAt']);
    }

    public function testMarksItDoneAndBackOpen(): void
    {
        $id = $this->handOverId();

        $done = $this->patch($id, ['done' => true]);
        self::assertSame('done', $done['state']);
        self::assertNotNull($done['closedAt']);

        $open = $this->patch($id, ['done' => false]);
        self::assertSame('open', $open['state']);
        self::assertNull($open['closedAt']);
    }

    public function testANewDueDateIsCheckedAgain(): void
    {
        $id = $this->handOverId();
        $this->connection()->executeStatement("UPDATE hand_over SET due_at = now() - interval '1 minute', overdue_at = now() WHERE id = :id", ['id' => $id]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->asyncTransport()->reset();

        $dueAt = new \DateTimeImmutable('+1 day');
        $handOver = $this->patch($id, ['dueAt' => $dueAt->format(\DATE_ATOM)]);

        self::assertNull($handOver['overdueAt']);
        self::assertCount(1, $this->dueChecks());
    }

    public function testNudgesTheDelegateInTheConversation(): void
    {
        $id = $this->handOverId();

        $this->client->jsonRequest('POST', '/api/v1/hand-overs/'.$id.'/nudge', ['html' => '<p>Hi Lisa, any news on the invoice?</p>'], $this->kim);
        self::assertResponseIsSuccessful();
        $handOver = $this->decodedResponse();

        self::assertNotNull($handOver['nudgedAt']);
        self::assertIsString($handOver['lastNudgeId']);
        $raw = $this->raw($handOver['lastNudgeId']);
        $headers = substr($raw, 0, (int) strpos($raw, "\r\n\r\n"));
        self::assertStringContainsString('To: Lisa Bakker <lisa@company.example>', $headers);
        self::assertStringContainsString('Subject: Re: Fwd: Invoice question', $headers);
        self::assertStringContainsString('In-Reply-To: <invoice-1@duinzicht.example>', $headers);
        self::assertStringContainsString('any news on the invoice?', quoted_printable_decode($raw));
    }

    public function testNudgesWithAnUndoWindow(): void
    {
        $id = $this->handOverId();
        $sendAt = new \DateTimeImmutable('+5 seconds');

        $this->client->jsonRequest('POST', '/api/v1/hand-overs/'.$id.'/nudge', ['html' => '<p>Any news?</p>', 'sendAt' => $sendAt->format(\DATE_ATOM)], $this->kim);
        self::assertResponseIsSuccessful();
        $nudgeId = $this->decodedResponse()['lastNudgeId'] ?? null;
        self::assertIsString($nudgeId);

        $this->client->jsonRequest('POST', '/api/v1/outgoing-messages/'.$nudgeId.'/cancel', [], $this->kim);
        self::assertResponseIsSuccessful();
        self::assertSame('cancelled', $this->decodedResponse()['status'] ?? null);
    }

    public function testCannotNudgeAClosedHandOver(): void
    {
        $id = $this->handOverId();
        $this->patch($id, ['done' => true]);

        $this->client->jsonRequest('POST', '/api/v1/hand-overs/'.$id.'/nudge', ['html' => '<p>Any news?</p>'], $this->kim);

        self::assertResponseStatusCodeSame(409);
    }

    public function testNudgingNeedsTheSendScope(): void
    {
        $id = $this->handOverId();

        $this->client->jsonRequest('POST', '/api/v1/hand-overs/'.$id.'/nudge', ['html' => '<p>Any news?</p>'], $this->userHeaders('kim@example.test', ['mail:read', 'mail:write']));

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheDeltaCarriesHandOversAndTheirWithdrawal(): void
    {
        $id = $this->handOverId();

        $this->client->request('GET', '/api/v1/sync/changes?since=0', server: $this->kim);
        $page = $this->decodedResponse();
        $synced = array_values(array_filter((array) ($page['handOvers'] ?? []), static fn (mixed $h): bool => \is_array($h) && $h['id'] === $id));
        self::assertCount(1, $synced);
        self::assertSame($this->messageIdBySubject('Invoice question'), $synced[0]['messageId']);
        self::assertIsString($synced[0]['threadId']);
        self::assertSame(['address' => 'lisa@company.example', 'name' => 'Lisa Bakker'], $synced[0]['delegate']);
        self::assertSame('open', $synced[0]['state']);
        $cursor = $page['cursor'] ?? null;
        self::assertIsInt($cursor);

        $this->client->request('DELETE', '/api/v1/hand-overs/'.$id, server: $this->kim);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/v1/sync/changes?since='.$cursor, server: $this->kim);
        self::assertSame([['type' => 'handOver', 'id' => $id]], $this->decodedResponse()['deleted'] ?? null);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'messageId' => $this->originalId,
            'delegate' => ['address' => 'lisa@company.example', 'name' => 'Lisa Bakker'],
            'note' => '  Can you check the double charge?  ',
            'dueAt' => new \DateTimeImmutable('+3 days')->format(\DATE_ATOM),
            'forwardId' => $this->forwardId,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<mixed>
     */
    private function handOver(array $overrides = []): array
    {
        $this->client->jsonRequest('POST', '/api/v1/hand-overs', $this->payload($overrides), $this->kim);
        self::assertResponseStatusCodeSame(201);

        return $this->decodedResponse();
    }

    private function handOverId(): string
    {
        $id = $this->handOver()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<mixed>
     */
    private function get(string $id): array
    {
        $this->client->request('GET', '/api/v1/hand-overs/'.$id, server: $this->kim);
        self::assertResponseIsSuccessful();

        return $this->decodedResponse();
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<mixed>
     */
    private function patch(string $id, array $changes): array
    {
        $this->client->request('PATCH', '/api/v1/hand-overs/'.$id, server: $this->kim + ['CONTENT_TYPE' => 'application/merge-patch+json'], content: json_encode($changes, \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        return $this->decodedResponse();
    }

    private function changeSeq(string $id): int
    {
        $seq = $this->connection()->fetchOne('SELECT change_seq FROM hand_over WHERE id = :id', ['id' => $id]);
        self::assertIsNumeric($seq);

        return (int) $seq;
    }

    private function ingest(string $uid, string $messageId, ?string $inReplyTo, string $subject, string $from, string $receivedAt): void
    {
        $sha = hash('sha256', $messageId);
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/messages', ['workerId' => 'w1', 'folder' => 'INBOX', 'messages' => [[
            'remoteUid' => $uid,
            'rawObjectKey' => 'raw/'.substr($sha, 0, 2).'/'.$sha,
            'size' => 100,
            'receivedAt' => $receivedAt,
            'messageId' => $messageId,
            'inReplyTo' => $inReplyTo,
            'references' => null === $inReplyTo ? [] : [$inReplyTo],
            'subject' => $subject,
            'from' => ['address' => $from],
            'to' => [['address' => 'kim@company.example']],
            'snippet' => $subject,
        ]]], $this->worker);
        self::assertResponseIsSuccessful();
    }

    private function messageIdBySubject(string $subject): string
    {
        $this->client->request('GET', '/api/v1/sync/changes?since=0', server: $this->kim);
        foreach ((array) ($this->decodedResponse()['messages'] ?? []) as $message) {
            if (\is_array($message) && $message['subject'] === $subject && \is_string($message['id'])) {
                return $message['id'];
            }
        }
        self::fail('No message with subject '.$subject);
    }

    private function raw(string $outgoingId): string
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $message = $entityManager->find(OutgoingMessage::class, Uuid::fromString($outgoingId));
        self::assertInstanceOf(OutgoingMessage::class, $message);

        return static::getContainer()->get(InMemoryObjectStorage::class)->read($message->getRawObjectKey());
    }

    /**
     * The due checks dispatched, with their delay in milliseconds.
     *
     * @return list<array{HandOverDue, ?int}>
     */
    private function dueChecks(): array
    {
        $checks = [];
        foreach ($this->asyncTransport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof HandOverDue) {
                $checks[] = [$message, $envelope->last(DelayStamp::class)?->getDelay()];
            }
        }

        return $checks;
    }

    private function overdueHandOver(): string
    {
        $id = $this->handOverId();
        $this->connection()->executeStatement("UPDATE hand_over SET due_at = now() - interval '1 minute' WHERE id = :id", ['id' => $id]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        static::getContainer()->get(HandOverDueHandler::class)(new HandOverDue($id));

        return $id;
    }

    private function notifyOverdue(string $id): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        static::getContainer()->get(NotifyOverdueHandOverHandler::class)(new NotifyOverdueHandOver($id));
    }

    /** @return array<string, string> */
    private function settings(): array
    {
        return $this->userHeaders('kim@example.test', ['settings:read', 'settings:write']);
    }

    private function registerPhone(): void
    {
        $this->http()->respond(self::EXPO_SEND, json_encode(['data' => [['status' => 'ok', 'id' => 'ticket-1']]], \JSON_THROW_ON_ERROR));
        $this->client->jsonRequest('POST', '/api/v1/notification-devices', ['kind' => 'expo', 'endpoint' => 'ExponentPushToken[abc123]', 'name' => 'Phone'], $this->settings());
        self::assertResponseIsSuccessful();
    }

    /**
     * What was sent to Expo, one entry per push.
     *
     * @return list<array<mixed>>
     */
    private function expoSends(): array
    {
        $sends = [];
        foreach ($this->http()->calls() as $call) {
            if (self::EXPO_SEND === $call['url']) {
                $body = json_decode($call['body'], true, flags: \JSON_THROW_ON_ERROR);
                self::assertIsArray($body);
                $sends[] = $body;
            }
        }

        return $sends;
    }

    /** @return list<NotifyOverdueHandOver> */
    private function notifications(): array
    {
        $transport = static::getContainer()->get('messenger.transport.push');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), [...$transport->getSent()]), static fn (object $message): bool => $message instanceof NotifyOverdueHandOver));
    }

    private function http(): MockHttpRouter
    {
        return static::getContainer()->get(MockHttpRouter::class);
    }

    /** @return list<string> */
    private function violationPaths(): array
    {
        $violations = $this->decodedResponse()['violations'] ?? [];
        self::assertIsArray($violations);

        return array_values(array_map(static fn (mixed $v): string => \is_array($v) && \is_string($v['propertyPath'] ?? null) ? $v['propertyPath'] : '', $violations));
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
