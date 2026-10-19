<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class SyncCredentialsTest extends DatabaseTestCase
{
    use AuthenticatesUsers;

    private string $accountId;

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
    }

    public function testLeasingWorkerReceivesDecryptedCredentials(): void
    {
        $worker = $this->workerHeaders('sync:lease sync:credentials');
        $this->lease('w1', $worker);

        $this->client->request('GET', $this->credentialsUrl('w1'), server: $worker);

        self::assertResponseIsSuccessful();
        self::assertSame([
            'accountId' => $this->accountId,
            'emailAddress' => 'kim@company.example',
            'provider' => 'imap',
            'connection' => [
                'imap' => ['host' => 'imap.company.example', 'port' => 993, 'security' => 'tls'],
                'smtp' => ['host' => 'smtp.company.example', 'port' => 465, 'security' => 'tls'],
                'username' => 'kim@company.example',
                'caldav' => null,
            ],
            'credentials' => ['type' => 'password', 'password' => 'app-password-1'],
        ], $this->decodedResponse());
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
    }

    public function testAccountNotLeasedIsNotFound(): void
    {
        $worker = $this->workerHeaders('sync:lease sync:credentials');

        $this->client->request('GET', $this->credentialsUrl('w1'), server: $worker);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAccountLeasedByAnotherWorkerIsNotFound(): void
    {
        $worker = $this->workerHeaders('sync:lease sync:credentials');
        $this->lease('w1', $worker);

        $this->client->request('GET', $this->credentialsUrl('w2'), server: $worker);

        self::assertResponseStatusCodeSame(404);
    }

    public function testExpiredLeaseIsNotFound(): void
    {
        $worker = $this->workerHeaders('sync:lease sync:credentials');
        $this->lease('w1', $worker);
        $this->connection()->executeStatement("UPDATE mail_account SET lease_expires_at = now() - interval '1 minute'");

        $this->client->request('GET', $this->credentialsUrl('w1'), server: $worker);

        self::assertResponseStatusCodeSame(404);
    }

    public function testWorkerIdIsRequired(): void
    {
        $worker = $this->workerHeaders('sync:credentials');

        $this->client->request('GET', '/internal/sync/accounts/'.$this->accountId.'/credentials', server: $worker);

        self::assertResponseStatusCodeSame(400);
    }

    public function testRequiresCredentialsScope(): void
    {
        $worker = $this->workerHeaders('sync:lease');
        $this->lease('w1', $worker);

        $this->client->request('GET', $this->credentialsUrl('w1'), server: $worker);

        self::assertResponseStatusCodeSame(403);
    }

    public function testUserTokenIsRefused(): void
    {
        $user = $this->userHeaders('kim@example.test', ['settings:read']);

        $this->client->request('GET', $this->credentialsUrl('w1'), server: $user);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, string> $headers
     */
    private function lease(string $workerId, array $headers): void
    {
        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => $workerId], $headers);
        self::assertResponseIsSuccessful();
        self::assertContains($this->accountId, array_column((array) ($this->decodedResponse()['accounts'] ?? []), 'id'));
    }

    private function credentialsUrl(string $workerId): string
    {
        return '/internal/sync/accounts/'.$this->accountId.'/credentials?workerId='.$workerId;
    }
}
