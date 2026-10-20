<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Search\MessageIndex;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Runs against a real Meilisearch (MEILISEARCH_URL) in an index of its own (MEILISEARCH_INDEX in .env.test). Indexing
 * is queued after commit like in production; the tests run the queue right after each request that wrote mail, since
 * the test client resets the in-memory transport before the next request.
 */
final class SearchTest extends DatabaseTestCase
{
    use AuthenticatesUsers;

    private User $kim;

    private User $lisa;

    /** @var array<string, string> */
    private array $kimHeaders;

    /** @var array<string, string> */
    private array $worker;

    private string $workAccount;

    private string $personalAccount;

    private string $lisaAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapOAuthClients();
        $this->kim = $this->createUser('kim@example.test', 'Kim');
        $this->lisa = $this->createUser('lisa@example.test', 'Lisa');
        $this->kimHeaders = $this->userHeaders('kim@example.test', ['mail:read', 'settings:write']);

        $this->workAccount = $this->createAccount($this->kimHeaders, 'kim@company.example', 'Work');
        $this->personalAccount = $this->createAccount($this->kimHeaders, 'kim@home.example', 'Personal');
        $this->lisaAccount = $this->createAccount($this->userHeaders('lisa@example.test', ['settings:write']), 'lisa@company.example', 'Work');

        $this->worker = $this->workerHeaders('sync:lease sync:ingest');
        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => 'w1'], $this->worker);
        self::assertResponseIsSuccessful();
        foreach ([$this->workAccount, $this->personalAccount, $this->lisaAccount] as $account) {
            $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$account.'/folders', ['workerId' => 'w1', 'folders' => [
                ['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1],
                ['remoteName' => 'Archive', 'displayName' => 'Archive', 'role' => 'archive', 'uidValidity' => 1],
                ['remoteName' => 'Invoices', 'displayName' => 'Invoices', 'role' => 'custom', 'uidValidity' => 1],
            ]], $this->worker);
            self::assertResponseIsSuccessful();
        }

        $this->ingest($this->workAccount, 'INBOX', [
            $this->message('1', 'Q4 budget, revised', 'anna@finance.example', 'Anna de Vries', '2026-10-19T09:12:00+00:00', [
                'bodyText' => 'I updated the Q4 numbers with the new hosting costs and the extra hire for support.',
                'attachments' => [['filename' => 'budget-q4-v3.xlsx', 'contentType' => 'application/vnd.ms-excel', 'size' => 48000, 'sha256' => hash('sha256', 'xlsx')]],
            ]),
            $this->message('2', 'Team day', 'pieter@company.example', 'Pieter', '2026-09-12T10:00:00+00:00', [
                'bodyText' => 'The budget is fifty euro per person including dinner.',
            ]),
        ]);
        $this->ingest($this->workAccount, 'Invoices', [
            $this->message('1', 'Invoice 42', 'billing@finance.example', null, '2026-10-02T08:00:00+00:00', [
                'bodyText' => 'Your invoice for October.',
                'attachments' => [['filename' => 'factuur-42.pdf', 'contentType' => 'application/pdf', 'size' => 212000, 'sha256' => hash('sha256', 'pdf')]],
            ]),
        ]);
        $this->ingest($this->personalAccount, 'INBOX', [
            $this->message('1', 'Holiday plans', 'sanne@friends.example', 'Sanne', '2026-08-01T18:00:00+00:00', [
                'to' => [['address' => 'kim@home.example']],
                'cc' => [['address' => 'jeroen@friends.example', 'name' => 'Jeroen Bakker']],
                'bodyText' => 'Shall we book the cabin for the first week?',
            ]),
        ]);
        $this->ingest($this->lisaAccount, 'INBOX', [
            $this->message('1', 'Q4 budget for marketing', 'anna@finance.example', 'Anna de Vries', '2026-10-18T09:00:00+00:00', [
                'bodyText' => 'Team day budget and the Q4 marketing budget.',
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        $index = $this->index();
        $index->deleteOwnedBy($this->kim->getId());
        $index->waitFor($index->deleteOwnedBy($this->lisa->getId()));

        parent::tearDown();
    }

    public function testFindsWordsInSubjectsAndBodiesNewestFirst(): void
    {
        $results = $this->search('budget');

        self::assertSame(['Q4 budget, revised', 'Team day'], $this->subjects($results));
        self::assertSame(2, $results['totalHits']);
        self::assertSame([['text' => 'Q4 ', 'match' => false], ['text' => 'budget', 'match' => true], ['text' => ', revised', 'match' => false]], $this->hit($results, 0)['subject']);
        $preview = $this->hit($results, 1)['preview'];
        self::assertIsArray($preview);
        self::assertContains(['text' => 'budget', 'match' => true], $preview, 'The preview is the passage of the body around the match.');
    }

    public function testFindsAttachmentNames(): void
    {
        $results = $this->search('factuur');

        self::assertSame(['Invoice 42'], $this->subjects($results));
        $attachments = $this->hit($results, 0)['attachments'];
        self::assertIsArray($attachments);
        self::assertIsArray($attachments[0]);
        self::assertTrue($attachments[0]['matched']);
        self::assertSame('pending', $attachments[0]['scanStatus'], 'Results show what the scan said, so blocked files stand out.');
        self::assertSame([['text' => 'factuur', 'match' => true], ['text' => '-42.pdf', 'match' => false]], $attachments[0]['filename']);
        self::assertSame(212000, $attachments[0]['size']);
    }

    public function testHitsCarryWhatTheResultShows(): void
    {
        $hit = $this->hit($this->search('cabin'), 0);

        self::assertSame($this->personalAccount, $hit['accountId']);
        self::assertSame('sanne@friends.example', $hit['fromAddress']);
        self::assertSame('Sanne', $hit['fromName']);
        self::assertSame('2026-08-01T18:00:00+00:00', $hit['receivedAt']);
        self::assertFalse($hit['seen']);
        self::assertFalse($hit['hasAttachments']);
        self::assertIsString($hit['threadId']);
        self::assertCount(1, (array) $hit['folderIds']);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function addressFilters(): iterable
    {
        yield 'name word' => ['from:anna', ['Q4 budget, revised']];
        yield 'quoted name' => ['from:"Anna de Vries" budget', ['Q4 budget, revised']];
        yield 'address' => ['from:billing@finance.example', ['Invoice 42']];
        yield 'domain' => ['from:finance.example', ['Q4 budget, revised', 'Invoice 42']];
        yield 'domain with at' => ['from:@company.example', ['Team day']];
        yield 'cc recipient' => ['to:jeroen', ['Holiday plans']];
        yield 'recipient address' => ['to:kim@home.example', ['Holiday plans']];
        yield 'no match' => ['from:nobody', []];
    }

    /**
     * @param list<string> $subjects
     */
    #[DataProvider('addressFilters')]
    public function testFiltersBySenderAndRecipients(string $query, array $subjects): void
    {
        self::assertSame($subjects, $this->subjects($this->search($query)));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function scopeFilters(): iterable
    {
        yield 'folder role' => ['in:inbox', ['Q4 budget, revised', 'Team day', 'Holiday plans']];
        yield 'folder name' => ['in:invoices', ['Invoice 42']];
        yield 'account name' => ['in:Personal', ['Holiday plans']];
        yield 'account address' => ['in:kim@company.example', ['Q4 budget, revised', 'Invoice 42', 'Team day']];
        yield 'role and words' => ['in:inbox budget', ['Q4 budget, revised', 'Team day']];
        yield 'nothing by that name' => ['in:nowhere', []];
        yield 'empty folder role' => ['in:trash', []];
        yield 'attachments' => ['has:attachment', ['Q4 budget, revised', 'Invoice 42']];
    }

    /**
     * @param list<string> $subjects
     */
    #[DataProvider('scopeFilters')]
    public function testFiltersByFolderAccountAndAttachments(string $query, array $subjects): void
    {
        self::assertSame($subjects, $this->subjects($this->search($query)));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function dateFilters(): iterable
    {
        yield 'after' => ['after:2026-10-02', ['Q4 budget, revised', 'Invoice 42']];
        yield 'before' => ['before:2026-10-02', ['Team day', 'Holiday plans']];
        yield 'range' => ['after:2026-10-01 before:2026-10-10', ['Invoice 42']];
    }

    /**
     * @param list<string> $subjects
     */
    #[DataProvider('dateFilters')]
    public function testFiltersByDate(string $query, array $subjects): void
    {
        self::assertSame($subjects, $this->subjects($this->search($query)));
    }

    public function testDatesAreDaysInTheRequestedTimeZone(): void
    {
        // Received 2026-08-01T18:00Z: still the 1st in Amsterdam, already the 2nd in Auckland.
        self::assertSame(['Holiday plans'], $this->subjects($this->search('after:2026-08-02 before:2026-08-03', ['timeZone' => 'Pacific/Auckland'])));
        self::assertSame([], $this->subjects($this->search('after:2026-08-02 before:2026-08-03', ['timeZone' => 'Europe/Amsterdam'])));
    }

    public function testNeverFindsAnotherUsersMail(): void
    {
        $lisa = $this->userHeaders('lisa@example.test', ['mail:read']);

        self::assertSame(['Q4 budget for marketing'], $this->subjects($this->search('budget', [], $lisa)));
        self::assertSame([], $this->subjects($this->search('cabin', [], $lisa)));
        self::assertSame([], $this->subjects($this->search('in:Personal', [], $lisa)));
        self::assertSame(['Q4 budget, revised', 'Team day'], $this->subjects($this->search('budget')), 'Lisa\'s message with the same words stays hers.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileValues(): iterable
    {
        yield 'trailing backslash' => ['from:x\\'];
        yield 'quotes' => ['from:x"OR(ownerId!="nobody")'];
        yield 'backslash in a recipient' => ['to:a\\"b'];
        yield 'backslash in a scope' => ['in:x\\'];
    }

    #[DataProvider('hostileValues')]
    public function testTypedValuesStayInsideTheirFilter(string $query): void
    {
        self::assertSame([], $this->subjects($this->search($query)));
    }

    public function testPagesThroughResults(): void
    {
        $results = $this->search('budget', ['page' => '2', 'perPage' => '1']);

        self::assertSame(['Team day'], $this->subjects($results));
        self::assertSame(2, $results['totalHits']);
        self::assertSame(2, $results['totalPages']);
        self::assertSame(2, $results['page']);
        self::assertSame(1, $results['perPage']);
    }

    public function testDeletedMailDisappears(): void
    {
        $this->changes($this->workAccount, 'INBOX', ['vanished' => ['2']]);

        self::assertSame(['Q4 budget, revised'], $this->subjects($this->search('budget')));
    }

    public function testMovedMailIsFoundInItsNewFolder(): void
    {
        $this->ingest($this->workAccount, 'Archive', [
            $this->message('7', 'Q4 budget, revised', 'anna@finance.example', 'Anna de Vries', '2026-10-19T09:12:00+00:00'),
        ]);
        $this->changes($this->workAccount, 'INBOX', ['vanished' => ['1']]);

        self::assertSame(['Q4 budget, revised'], $this->subjects($this->search('in:archive budget')));
        self::assertSame(['Team day'], $this->subjects($this->search('in:inbox budget')));
    }

    public function testFlagChangesReachTheResults(): void
    {
        $this->changes($this->personalAccount, 'INBOX', ['flags' => [['remoteUid' => '1', 'flags' => ['seen' => true, 'flagged' => true, 'answered' => false, 'draft' => false]]]]);

        $hit = $this->hit($this->search('cabin'), 0);
        self::assertTrue($hit['seen']);
        self::assertTrue($hit['flagged']);
    }

    public function testRemovedAccountsLeaveTheIndex(): void
    {
        $this->client->request('DELETE', '/api/v1/mail-accounts/'.$this->personalAccount, server: $this->kimHeaders);
        self::assertResponseStatusCodeSame(204);
        $this->runIndexing();

        self::assertSame([], $this->subjects($this->search('cabin')));
        self::assertSame(3, $this->indexedFor($this->kim), 'The documents of the removed account are deleted, not only hidden.');
    }

    public function testReindexRestoresAUsersDocuments(): void
    {
        $index = $this->index();
        $index->waitFor($index->deleteOwnedBy($this->kim->getId()));
        self::assertSame([], $this->subjects($this->search('budget')));

        $tester = $this->reindex(['--user' => ['kim@example.test']]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('kim@example.test: 4 messages', $tester->getDisplay());
        self::assertSame(['Q4 budget, revised', 'Team day'], $this->subjects($this->search('budget')));
        self::assertSame(1, $this->indexedFor($this->lisa), 'Other users are left alone.');
    }

    public function testReindexAllRebuildsEveryone(): void
    {
        $tester = $this->reindex(['--all' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(4, $this->indexedFor($this->kim));
        self::assertSame(1, $this->indexedFor($this->lisa));
    }

    public function testReindexNeedsUsersOrAll(): void
    {
        self::assertSame(Command::INVALID, $this->reindex([])->getStatusCode());
        self::assertSame(Command::INVALID, $this->reindex(['--all' => true, '--user' => ['kim@example.test']])->getStatusCode());
        self::assertSame(Command::FAILURE, $this->reindex(['--user' => ['nobody@example.test']])->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'blank' => ['   ', 'Type something to search for.'];
        yield 'only an empty filter' => ['from:', 'Type something to search for.'];
        yield 'bad date' => ['before:soon', '"before:soon" needs a date like before:2026-10-01.'];
        yield 'unknown has' => ['has:stars', '"has:stars" is not a filter. Use has:attachment.'];
    }

    #[DataProvider('invalidQueries')]
    public function testExplainsQueriesThatCannotRun(string $query, string $message): void
    {
        $this->client->request('GET', '/api/v1/search?'.http_build_query(['q' => $query]), server: $this->kimHeaders);

        self::assertResponseStatusCodeSame(422);
        $violations = $this->decodedResponse()['violations'] ?? null;
        self::assertIsArray($violations);
        self::assertSame(['propertyPath' => 'q', 'message' => $message], array_intersect_key((array) $violations[0], ['propertyPath' => true, 'message' => true]));
    }

    public function testRejectsBadPaging(): void
    {
        $this->client->request('GET', '/api/v1/search?'.http_build_query(['q' => 'budget', 'perPage' => '500']), server: $this->kimHeaders);

        self::assertResponseStatusCodeSame(422);
    }

    public function testRequiresTheMailReadScope(): void
    {
        $this->client->request('GET', '/api/v1/search?q=budget', server: $this->userHeaders('kim@example.test', ['settings:read']));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/api/v1/search?q=budget', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testWorkersCannotSearch(): void
    {
        $this->client->request('GET', '/api/v1/search?q=budget', server: $this->worker);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Handles what the last request queued, the way the messenger worker would, then waits for Meilisearch.
     */
    private function runIndexing(): void
    {
        $transport = static::getContainer()->get('messenger.transport.search');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $bus = static::getContainer()->get(MessageBusInterface::class);

        $envelopes = $transport->get();
        $transport->reset();
        foreach ($envelopes as $envelope) {
            $bus->dispatch($envelope->with(new ReceivedStamp('search')));
        }

        // Meilisearch runs tasks in order: once this one is done, so is everything queued before it.
        $index = $this->index();
        $index->waitFor($index->configure());
    }

    private function indexedFor(User $user): int
    {
        $response = $this->index()->search(['q' => '', 'filter' => 'ownerId = '.MessageIndex::quote($user->getId()->toRfc4122()), 'hitsPerPage' => 0]);
        self::assertIsArray($response);
        self::assertIsInt($response['totalHits'] ?? null);

        return $response['totalHits'];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function reindex(array $input): CommandTester
    {
        $tester = new CommandTester(new Application($this->client->getKernel())->find('app:search:reindex'));
        $tester->execute($input);

        return $tester;
    }

    /**
     * @param array<string, string>      $parameters
     * @param array<string, string>|null $headers
     *
     * @return array<mixed>
     */
    private function search(string $query, array $parameters = [], ?array $headers = null): array
    {
        $this->client->request('GET', '/api/v1/search?'.http_build_query(['q' => $query] + $parameters), server: $headers ?? $this->kimHeaders);
        self::assertResponseIsSuccessful();

        return $this->decodedResponse();
    }

    /**
     * @param array<mixed> $results
     *
     * @return list<string>
     */
    private function subjects(array $results): array
    {
        self::assertIsArray($results['messages'] ?? null);
        $subjects = [];
        foreach ($results['messages'] as $hit) {
            self::assertIsArray($hit);
            self::assertIsArray($hit['subject']);
            $subjects[] = implode('', array_map(static fn (mixed $segment): string => \is_array($segment) && \is_string($segment['text'] ?? null) ? $segment['text'] : '', $hit['subject']));
        }

        return $subjects;
    }

    /**
     * @param array<mixed> $results
     *
     * @return array<string, mixed>
     */
    private function hit(array $results, int $position): array
    {
        self::assertIsArray($results['messages'] ?? null);
        $hit = $results['messages'][$position] ?? null;
        self::assertIsArray($hit);

        /** @var array<string, mixed> $hit */
        return $hit;
    }

    /**
     * @param array<string, string> $headers
     */
    private function createAccount(array $headers, string $emailAddress, string $displayName): string
    {
        $this->client->jsonRequest('POST', '/api/v1/mail-accounts', [
            'emailAddress' => $emailAddress,
            'displayName' => $displayName,
            'username' => $emailAddress,
            'password' => 'app-password-1',
            'imap' => ['host' => 'imap.company.example', 'port' => 993, 'security' => 'tls'],
            'smtp' => ['host' => 'smtp.company.example', 'port' => 465, 'security' => 'tls'],
        ], $headers);
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('id');
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function ingest(string $account, string $folder, array $messages): void
    {
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$account.'/messages', ['workerId' => 'w1', 'folder' => $folder, 'messages' => $messages], $this->worker);
        self::assertResponseIsSuccessful();
        $this->runIndexing();
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function changes(string $account, string $folder, array $changes): void
    {
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$account.'/changes', ['workerId' => 'w1', 'folder' => $folder] + $changes, $this->worker);
        self::assertResponseIsSuccessful();
        $this->runIndexing();
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function message(string $uid, string $subject, string $from, ?string $fromName, string $receivedAt, array $override = []): array
    {
        $sha = hash('sha256', $subject.$uid);

        return $override + [
            'remoteUid' => $uid,
            'rawObjectKey' => 'raw/'.substr($sha, 0, 2).'/'.$sha,
            'size' => 2048,
            'receivedAt' => $receivedAt,
            'flags' => ['seen' => false, 'flagged' => false, 'answered' => false, 'draft' => false],
            'messageId' => '<'.md5($subject).'@test.example>',
            'subject' => $subject,
            'from' => ['address' => $from, 'name' => $fromName],
            'to' => [['address' => 'kim@company.example']],
            'snippet' => $subject,
        ];
    }

    private function index(): MessageIndex
    {
        return static::getContainer()->get(MessageIndex::class);
    }
}
