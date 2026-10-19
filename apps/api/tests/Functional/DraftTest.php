<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Draft;
use App\Entity\DraftCopyRemoval;
use App\Tests\Support\InMemoryObjectStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DraftTest extends DatabaseTestCase
{
    use AuthenticatesUsers;

    private string $accountId;

    private string $otherAccountId;

    /** @var array<string, string> */
    private array $kim;

    /** @var array<string, string> */
    private array $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapOAuthClients();
        $this->createUser('kim@example.test', 'Kim');
        $this->createUser('lisa@example.test', 'Lisa');
        $this->kim = $this->userHeaders('kim@example.test', ['mail:read', 'mail:write', 'mail:send', 'settings:write', 'signatures:write']);
        $this->accountId = $this->createAccount('kim@company.example');
        $this->otherAccountId = $this->createAccount('kim@home.example');
        $this->worker = $this->workerHeaders('sync:lease sync:ingest');
        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => 'w1'], $this->worker);
        self::assertResponseIsSuccessful();
    }

    public function testCreatesADraftWithTheClientsId(): void
    {
        $id = Uuid::v7()->toRfc4122();

        $draft = $this->save(null, ['draftId' => $id, 'to' => [['address' => 'anna@finance.ex', 'name' => 'Anna']], 'subject' => 'Q4', 'html' => '<p>Half a sent', 'kind' => 'new']);

        self::assertSame($id, $draft['id']);
        self::assertSame(1, $draft['version']);
        self::assertSame([['address' => 'anna@finance.ex', 'name' => 'Anna']], $draft['to'], 'An unfinished address is fine in a draft.');
        self::assertSame($this->accountId, $draft['mailAccountId']);
    }

    public function testUpdatesRaiseTheVersionAndRefuseStaleOnes(): void
    {
        $draft = $this->save(null, ['subject' => 'One']);

        $updated = $this->save($this->idOf($draft), ['subject' => 'Two', 'expectedVersion' => 1]);
        self::assertSame(2, $updated['version']);
        self::assertSame('Two', $updated['subject']);

        $this->client->jsonRequest('PUT', '/api/v1/drafts/'.$this->idOf($draft), $this->payload(['subject' => 'Stale', 'expectedVersion' => 1]), $this->kim);
        self::assertResponseStatusCodeSame(409);
    }

    public function testRefersOnlyToTheUsersOwnThings(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/drafts', $this->payload(['signatureId' => Uuid::v7()->toRfc4122()]), $this->kim);
        self::assertResponseStatusCodeSame(422);

        $lisa = $this->userHeaders('lisa@example.test', ['mail:read', 'mail:write']);
        $draft = $this->save(null, ['subject' => 'Mine']);
        $this->client->request('GET', '/api/v1/drafts/'.$this->idOf($draft), server: $lisa);
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/api/v1/drafts', $this->payload([]), $lisa);
        self::assertResponseStatusCodeSame(422, "Lisa cannot write from Kim's account.");
    }

    public function testDraftsReachEveryDeviceThroughTheDelta(): void
    {
        $draft = $this->save(null, ['subject' => 'Synced']);

        $this->client->request('GET', '/api/v1/sync/changes?since=0', server: $this->kim);
        $drafts = $this->decodedResponse()['drafts'] ?? null;
        self::assertIsArray($drafts);
        self::assertCount(1, $drafts);
        self::assertIsArray($drafts[0]);
        self::assertSame(['id' => $draft['id'], 'subject' => 'Synced', 'version' => 1], array_intersect_key($drafts[0], ['id' => 0, 'subject' => 0, 'version' => 0]));
        $cursor = $this->cursor();

        $this->client->request('DELETE', '/api/v1/drafts/'.$this->idOf($draft), server: $this->kim);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/v1/sync/changes?since='.$cursor, server: $this->kim);
        self::assertContains(['type' => 'draft', 'id' => $this->idOf($draft)], (array) ($this->decodedResponse()['deleted'] ?? []));
    }

    public function testAWorkerFilesARestedDraftAndRemovesTheCopyBefore(): void
    {
        $draft = $this->save(null, ['to' => [['address' => 'anna@finance.example', 'name' => null]], 'bcc' => [['address' => 'archive@company.example', 'name' => null]], 'subject' => 'Plan']);
        $id = $this->idOf($draft);

        self::assertSame([], $this->claim()['files'], 'A draft that was just edited rests first.');

        $this->rest($id);
        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => 'w1'], $this->worker);
        self::assertContains($this->accountId, (array) ($this->decodedResponse()['outbox'] ?? []));

        $files = $this->claim()['files'];
        self::assertCount(1, $files);
        self::assertIsArray($files[0]);
        self::assertSame(['draftId' => $id, 'version' => 1, 'messageId' => $id.'.1@company.example', 'previousMessageId' => null], array_diff_key($files[0], ['rawObjectKey' => 0]));
        self::assertIsString($files[0]['rawObjectKey']);
        $raw = static::getContainer()->get(InMemoryObjectStorage::class)->read($files[0]['rawObjectKey']);
        self::assertStringContainsString('Bcc: archive@company.example', $raw, 'The copy keeps Bcc so other mail apps can go on writing it.');
        self::assertStringContainsString('X-Tresso-Draft: '.$id, $raw);
        self::assertSame([], $this->claim()['files'], 'A claimed draft is not handed out twice.');

        $this->filed($id, 1, $id.'.1@company.example');
        self::assertResponseIsSuccessful();

        $this->save($id, ['subject' => 'Plan v2', 'expectedVersion' => 1]);
        $this->rest($id);
        $files = $this->claim()['files'];
        self::assertIsArray($files[0] ?? null);
        self::assertSame($id.'.1@company.example', $files[0]['previousMessageId']);
    }

    public function testSendingADraftRemovesItAndItsCopy(): void
    {
        $id = $this->newDraft(['to' => [['address' => 'anna@finance.example', 'name' => null]], 'subject' => 'Go']);
        $this->rest($id);
        $this->claim();
        $this->filed($id, 1, $id.'.1@company.example');

        $this->client->jsonRequest('POST', '/api/v1/outgoing-messages', ['mailAccountId' => $this->accountId, 'to' => [['address' => 'anna@finance.example']], 'subject' => 'Go', 'html' => '<p>Go</p>', 'draftId' => $id], $this->kim);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/v1/drafts/'.$id, server: $this->kim);
        self::assertResponseStatusCodeSame(404);
        $removals = $this->claim()['removals'];
        self::assertCount(1, $removals);
        self::assertIsArray($removals[0]);
        self::assertSame($id.'.1@company.example', $removals[0]['messageId']);

        self::assertIsString($removals[0]['id']);
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/draft-removals/'.$removals[0]['id'].'/done', ['workerId' => 'w1'], $this->worker);
        self::assertResponseIsSuccessful();
        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)->getRepository(DraftCopyRemoval::class)->count([]));
    }

    public function testMovingADraftToAnotherAccountRemovesTheOldCopy(): void
    {
        $id = $this->newDraft(['subject' => 'Moving']);
        $this->rest($id);
        $this->claim();
        $this->filed($id, 1, $id.'.1@company.example');

        $this->save($id, ['mailAccountId' => $this->otherAccountId, 'expectedVersion' => 1]);

        $removals = $this->claim()['removals'];
        self::assertIsArray($removals[0] ?? null);
        self::assertSame($id.'.1@company.example', $removals[0]['messageId']);
        $draft = static::getContainer()->get(EntityManagerInterface::class)->find(Draft::class, Uuid::fromString($id));
        self::assertInstanceOf(Draft::class, $draft);
        self::assertSame(0, $draft->getFiledVersion());
    }

    public function testACopyFiledAfterTheDraftWasDeletedIsRemovedAgain(): void
    {
        $id = $this->newDraft(['subject' => 'Gone']);
        $this->rest($id);
        $this->claim();
        $this->client->request('DELETE', '/api/v1/drafts/'.$id, server: $this->kim);

        $this->filed($id, 1, $id.'.1@company.example');

        self::assertResponseIsSuccessful();
        self::assertFalse($this->decodedResponse()['accepted'] ?? true);
        $removals = $this->claim()['removals'];
        self::assertIsArray($removals[0] ?? null);
        self::assertSame($id.'.1@company.example', $removals[0]['messageId']);
    }

    public function testDeletingItsSignatureReachesTheDraftOnEveryDevice(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/signatures', ['name' => 'Work', 'html' => '<p>Kim</p>', 'defaultFor' => []], $this->kim);
        self::assertResponseStatusCodeSame(201);
        $signatureId = $this->responseString('id');
        $id = $this->newDraft(['signatureId' => $signatureId]);
        $this->client->request('GET', '/api/v1/sync/changes?since=0', server: $this->kim);
        $cursor = $this->cursor();

        $this->client->request('DELETE', '/api/v1/signatures/'.$signatureId, server: $this->kim);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/v1/sync/changes?since='.$cursor, server: $this->kim);
        $drafts = $this->decodedResponse()['drafts'] ?? null;
        self::assertIsArray($drafts);
        self::assertSame([['id' => $id, 'signatureId' => null]], array_map(static fn (mixed $d): array => \is_array($d) ? ['id' => $d['id'], 'signatureId' => $d['signatureId']] : [], $drafts));
    }

    public function testRemovingTheAccountKeepsTheDraftOnEveryDevice(): void
    {
        $id = $this->newDraft(['subject' => 'Orphan']);
        $this->client->request('GET', '/api/v1/sync/changes?since=0', server: $this->kim);
        $cursor = $this->cursor();

        $this->client->request('DELETE', '/api/v1/mail-accounts/'.$this->accountId, server: $this->kim);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/v1/sync/changes?since='.$cursor, server: $this->kim);
        $drafts = $this->decodedResponse()['drafts'] ?? null;
        self::assertIsArray($drafts);
        self::assertSame([['id' => $id, 'mailAccountId' => null]], array_map(static fn (mixed $d): array => \is_array($d) ? ['id' => $d['id'], 'mailAccountId' => $d['mailAccountId']] : [], $drafts));
    }

    public function testRequiresWriteScope(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/drafts', $this->payload([]), $this->userHeaders('kim@example.test', ['mail:read']));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function payload(array $changes): array
    {
        return $changes + ['mailAccountId' => $this->accountId, 'kind' => 'new', 'to' => [], 'cc' => [], 'bcc' => [], 'subject' => '', 'html' => '', 'quoteHtml' => ''];
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function save(?string $id, array $changes): array
    {
        if (null === $id) {
            $this->client->jsonRequest('POST', '/api/v1/drafts', $this->payload($changes), $this->kim);
            self::assertResponseStatusCodeSame(201);
        } else {
            $this->client->jsonRequest('PUT', '/api/v1/drafts/'.$id, $this->payload($changes), $this->kim);
            self::assertResponseIsSuccessful();
        }

        /** @var array<string, mixed> */
        return $this->decodedResponse();
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function newDraft(array $changes): string
    {
        return $this->idOf($this->save(null, $changes));
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function idOf(array $draft): string
    {
        self::assertIsString($draft['id'] ?? null);

        return $draft['id'];
    }

    private function cursor(): int
    {
        $cursor = $this->decodedResponse()['cursor'] ?? null;
        self::assertIsInt($cursor);

        return $cursor;
    }

    private function rest(string $id): void
    {
        $this->connection()->executeStatement("UPDATE draft SET updated_at = now() - interval '1 minute' WHERE id = :id", ['id' => $id]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * @return array{files: list<mixed>, removals: list<mixed>}
     */
    private function claim(): array
    {
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/drafts/claim', ['workerId' => 'w1'], $this->worker);
        self::assertResponseIsSuccessful();
        $work = $this->decodedResponse();
        self::assertIsArray($work['files'] ?? null);
        self::assertIsArray($work['removals'] ?? null);

        return ['files' => array_values($work['files']), 'removals' => array_values($work['removals'])];
    }

    private function filed(string $id, int $version, string $messageId): void
    {
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/drafts/'.$id.'/filed', ['workerId' => 'w1', 'version' => $version, 'messageId' => $messageId], $this->worker);
    }

    private function createAccount(string $address): string
    {
        $this->client->jsonRequest('POST', '/api/v1/mail-accounts', [
            'emailAddress' => $address,
            'displayName' => 'Kim',
            'username' => $address,
            'password' => 'app-password-1',
            'imap' => ['host' => 'imap.company.example', 'port' => 993, 'security' => 'tls'],
            'smtp' => ['host' => 'smtp.company.example', 'port' => 465, 'security' => 'tls'],
        ], $this->kim);
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('id');
    }
}
