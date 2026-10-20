<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Security\Crypto\UserKeys;
use App\Storage\BlobFormat;
use App\Storage\BlobVault;
use App\Tests\Support\InMemoryObjectStorage;

final class SyncStorageKeysTest extends DatabaseTestCase
{
    use AuthenticatesUsers;
    use StoresMail;

    private string $accountId;

    /** @var array<string, string> */
    private array $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapOAuthClients();
        $this->createUser('kim@example.test', 'Kim Visser');

        $this->client->jsonRequest('POST', '/api/v1/mail-accounts', [
            'emailAddress' => 'kim@company.example',
            'displayName' => 'Work',
            'username' => 'kim@company.example',
            'password' => 'app-password-1',
            'imap' => ['host' => 'imap.company.example', 'port' => 993, 'security' => 'tls'],
            'smtp' => ['host' => 'smtp.company.example', 'port' => 465, 'security' => 'tls'],
        ], $this->userHeaders('kim@example.test', ['settings:write']));
        self::assertResponseStatusCodeSame(201);
        $this->accountId = $this->responseString('id');
        $this->worker = $this->workerHeaders('sync:lease sync:credentials');
    }

    public function testLeasingWorkerGetsTheOwnersBlobKeysAndNothingElse(): void
    {
        $this->lease('w1');

        $this->client->request('GET', $this->url('w1'), server: $this->worker);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        $keys = $this->decodedResponse();
        $owner = $this->accountOwner($this->accountId);
        $userKeys = static::getContainer()->get(UserKeys::class);
        self::assertSame(['accountId', 'ownerId', 'indexKey', 'currentVersion', 'blobKeys'], array_keys($keys));
        self::assertSame($owner->toRfc4122(), $keys['ownerId']);
        self::assertSame(1, $keys['currentVersion']);
        self::assertSame(base64_encode($userKeys->indexKey($owner)), $keys['indexKey']);
        self::assertSame([['version' => 1, 'key' => base64_encode(UserKeys::blobKey($userKeys->version($owner, 1)))]], $keys['blobKeys']);
        self::assertNotContains(base64_encode($userKeys->version($owner, 1)), array_column($keys['blobKeys'], 'key'), 'Never the user key itself.');
    }

    public function testWhatTheWorkerStoresWithTheseKeysTheApiReads(): void
    {
        $this->lease('w1');
        $this->client->request('GET', $this->url('w1'), server: $this->worker);
        $keys = $this->decodedResponse();
        self::assertIsString($keys['indexKey']);
        self::assertIsString($keys['ownerId']);
        self::assertIsArray($keys['blobKeys']);
        self::assertIsArray($keys['blobKeys'][0] ?? null);
        self::assertIsString($keys['blobKeys'][0]['key']);
        $content = 'Subject: stored by the worker';
        $fingerprint = hash_hmac('sha256', hash('sha256', $content, true), (string) base64_decode($keys['indexKey'], true));
        $sealed = fopen('php://memory', 'w+b');
        self::assertIsResource($sealed);
        BlobFormat::encrypt((string) base64_decode($keys['blobKeys'][0]['key'], true), 1, $this->accountOwner($this->accountId), $fingerprint, [$content], $sealed);
        rewind($sealed);

        self::assertSame($this->rawKeyFor($this->accountId, $content), \sprintf('raw/%s/%s/%s', $keys['ownerId'], substr($fingerprint, 0, 2), $fingerprint));
        static::getContainer()->get(InMemoryObjectStorage::class)->put($this->rawKeyFor($this->accountId, $content), (string) stream_get_contents($sealed));
        self::assertSame($content, static::getContainer()->get(BlobVault::class)->read($this->accountOwner($this->accountId), $this->rawKeyFor($this->accountId, $content)));
    }

    public function testAfterARotationEveryVersionStillInUseIsHandedOut(): void
    {
        static::getContainer()->get(UserKeys::class)->rotate($this->accountOwner($this->accountId), new \DateTimeImmutable());
        $this->lease('w1');

        $this->client->request('GET', $this->url('w1'), server: $this->worker);

        $keys = $this->decodedResponse();
        self::assertSame(2, $keys['currentVersion']);
        self::assertIsArray($keys['blobKeys']);
        self::assertSame([1, 2], array_column($keys['blobKeys'], 'version'));
    }

    public function testAccountNotLeasedIsNotFound(): void
    {
        $this->client->request('GET', $this->url('w1'), server: $this->worker);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAccountLeasedByAnotherWorkerIsNotFound(): void
    {
        $this->lease('w1');

        $this->client->request('GET', $this->url('w2'), server: $this->worker);

        self::assertResponseStatusCodeSame(404);
    }

    public function testNeedsTheCredentialsScope(): void
    {
        $worker = $this->workerHeaders('sync:lease sync:ingest');
        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => 'w1'], $worker);

        $this->client->request('GET', $this->url('w1'), server: $worker);

        self::assertResponseStatusCodeSame(403);
    }

    private function lease(string $workerId): void
    {
        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => $workerId], $this->worker);
        self::assertResponseIsSuccessful();
    }

    private function url(string $workerId): string
    {
        return '/internal/sync/accounts/'.$this->accountId.'/storage-keys?workerId='.$workerId;
    }
}
