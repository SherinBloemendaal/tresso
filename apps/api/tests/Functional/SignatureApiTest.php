<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class SignatureApiTest extends DatabaseTestCase
{
    use AuthenticatesUsers;

    private const array READ_WRITE = ['signatures:read', 'signatures:write', 'settings:read', 'settings:write'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapOAuthClients();
        $this->createUser('kim@example.test', 'Kim Visser');
        $this->createUser('lisa@example.test', 'Lisa Bakker');
    }

    public function testCreateStoresSanitizedHtml(): void
    {
        $headers = $this->userHeaders('kim@example.test', self::READ_WRITE);

        $this->client->jsonRequest('POST', '/api/v1/signatures', [
            'name' => '  Work  ',
            'html' => '<p style="color: #16181D" onclick="steal()">Kim Visser</p><script>steal()</script>',
        ], $headers);

        self::assertResponseStatusCodeSame(201);
        $body = $this->decodedResponse();
        self::assertSame('Work', $body['name'] ?? null);
        self::assertSame('<p style="color: #16181D">Kim Visser</p>', $body['html'] ?? null);
        self::assertSame([], $body['defaultFor'] ?? null);
        self::assertIsString($body['updatedAt'] ?? null);

        $this->client->request('GET', '/api/v1/signatures/'.$this->responseString('id'), server: $headers);
        self::assertResponseIsSuccessful();
        self::assertSame('<p style="color: #16181D">Kim Visser</p>', $this->decodedResponse()['html'] ?? null);
    }

    public function testListIsSortedByName(): void
    {
        $headers = $this->userHeaders('kim@example.test', self::READ_WRITE);
        $this->createSignature($headers, 'Support');
        $this->createSignature($headers, 'Short reply');

        $this->client->request('GET', '/api/v1/signatures', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(['Short reply', 'Support'], array_column($this->decodedResponse(), 'name'));
    }

    public function testDefaultMovesBetweenSignatures(): void
    {
        $headers = $this->userHeaders('kim@example.test', self::READ_WRITE);
        $work = $this->createAccount($headers, 'kim@company.example');
        $personal = $this->createAccount($headers, 'kim@home.example');

        $this->client->jsonRequest('POST', '/api/v1/signatures', ['name' => 'Work', 'html' => '<p>Kim</p>', 'defaultFor' => [$work, $personal]], $headers);
        self::assertResponseStatusCodeSame(201);
        $first = $this->responseString('id');
        self::assertEqualsCanonicalizing([$work, $personal], $this->decodedResponse()['defaultFor'] ?? null);

        $this->client->jsonRequest('POST', '/api/v1/signatures', ['name' => 'Personal', 'html' => '<p>K.</p>', 'defaultFor' => [$personal]], $headers);
        self::assertResponseStatusCodeSame(201);
        $second = $this->responseString('id');

        $this->client->request('GET', '/api/v1/signatures/'.$first, server: $headers);
        self::assertSame([$work], $this->decodedResponse()['defaultFor'] ?? null, 'An account has one default signature.');
        $this->client->request('GET', '/api/v1/mail-accounts/'.$personal, server: $headers);
        self::assertSame($second, $this->decodedResponse()['defaultSignatureId'] ?? null);

        $this->patch($headers, $first, ['defaultFor' => []]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->decodedResponse()['defaultFor'] ?? null);
        $this->client->request('GET', '/api/v1/mail-accounts/'.$work, server: $headers);
        $account = $this->decodedResponse();
        self::assertArrayHasKey('defaultSignatureId', $account);
        self::assertNull($account['defaultSignatureId']);
    }

    public function testUpdateChangesOnlyGivenFields(): void
    {
        $headers = $this->userHeaders('kim@example.test', self::READ_WRITE);
        $account = $this->createAccount($headers, 'kim@company.example');
        $this->client->jsonRequest('POST', '/api/v1/signatures', ['name' => 'Work', 'html' => '<p>Kim</p>', 'defaultFor' => [$account]], $headers);
        $id = $this->responseString('id');

        $this->patch($headers, $id, ['html' => '<p>Kim Visser</p><iframe src="https://x.example"></iframe>']);

        self::assertResponseIsSuccessful();
        $body = $this->decodedResponse();
        self::assertSame('Work', $body['name'] ?? null);
        self::assertSame('<p>Kim Visser</p>', $body['html'] ?? null);
        self::assertSame([$account], $body['defaultFor'] ?? null, 'Omitted defaults stay as they are.');
    }

    public function testDeleteClearsTheDefault(): void
    {
        $headers = $this->userHeaders('kim@example.test', self::READ_WRITE);
        $account = $this->createAccount($headers, 'kim@company.example');
        $this->client->jsonRequest('POST', '/api/v1/signatures', ['name' => 'Work', 'html' => '<p>Kim</p>', 'defaultFor' => [$account]], $headers);
        $id = $this->responseString('id');

        $this->client->request('DELETE', '/api/v1/signatures/'.$id, server: $headers);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/v1/signatures/'.$id, server: $headers);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/v1/mail-accounts/'.$account, server: $headers);
        $body = $this->decodedResponse();
        self::assertArrayHasKey('defaultSignatureId', $body);
        self::assertNull($body['defaultSignatureId']);
    }

    public function testUsersOnlySeeTheirOwnSignatures(): void
    {
        $kim = $this->userHeaders('kim@example.test', self::READ_WRITE);
        $kimsSignature = $this->createSignature($kim, 'Work');

        $lisa = $this->userHeaders('lisa@example.test', self::READ_WRITE);
        $this->client->request('GET', '/api/v1/signatures', server: $lisa);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->decodedResponse());

        $this->client->request('GET', '/api/v1/signatures/'.$kimsSignature, server: $lisa);
        self::assertResponseStatusCodeSame(404);

        $this->patch($lisa, $kimsSignature, ['name' => 'Taken']);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('DELETE', '/api/v1/signatures/'.$kimsSignature, server: $lisa);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/v1/signatures', server: $kim);
        self::assertSame(['Work'], array_column($this->decodedResponse(), 'name'));
    }

    public function testCannotBeTheDefaultOfAnotherUsersAccount(): void
    {
        $kim = $this->userHeaders('kim@example.test', self::READ_WRITE);
        $kimsAccount = $this->createAccount($kim, 'kim@company.example');

        $lisa = $this->userHeaders('lisa@example.test', self::READ_WRITE);
        $this->client->jsonRequest('POST', '/api/v1/signatures', ['name' => 'Lisa', 'html' => '<p>Lisa</p>', 'defaultFor' => [$kimsAccount]], $lisa);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('"propertyPath":"defaultFor[0]"', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/api/v1/signatures', server: $lisa);
        self::assertSame([], $this->decodedResponse(), 'Nothing is stored when the request is rejected.');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'blank name' => [['name' => '   '], 'name'];
        yield 'long name' => [['name' => str_repeat('a', 121)], 'name'];
        yield 'too much html' => [['html' => str_repeat('<p>Signature</p>', 12_501)], 'html'];
        yield 'invalid account id' => [['defaultFor' => ['not-a-uuid']], 'defaultFor[0]'];
        yield 'repeated account id' => [['defaultFor' => ['0199f5d0-0000-7000-8000-000000000000', '0199f5d0-0000-7000-8000-000000000000']], 'defaultFor'];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPayloads')]
    public function testInvalidInputIsRejected(array $override, string $path): void
    {
        $headers = $this->userHeaders('kim@example.test', self::READ_WRITE);

        $this->client->jsonRequest('POST', '/api/v1/signatures', $override + ['name' => 'Work', 'html' => '<p>Kim</p>'], $headers);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('"propertyPath":"'.$path.'"', (string) $this->client->getResponse()->getContent());
    }

    public function testWritingRequiresSignaturesWriteScope(): void
    {
        $readOnly = $this->userHeaders('kim@example.test', ['signatures:read']);

        $this->client->jsonRequest('POST', '/api/v1/signatures', ['name' => 'Work', 'html' => '<p>Kim</p>'], $readOnly);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/api/v1/signatures', server: $readOnly);
        self::assertResponseIsSuccessful();
    }

    public function testReadingRequiresSignaturesReadScope(): void
    {
        $settingsOnly = $this->userHeaders('kim@example.test', ['settings:read', 'settings:write']);

        $this->client->request('GET', '/api/v1/signatures', server: $settingsOnly);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRequiresToken(): void
    {
        $this->client->request('GET', '/api/v1/signatures', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @param array<string, string> $headers
     */
    private function createSignature(array $headers, string $name): string
    {
        $this->client->jsonRequest('POST', '/api/v1/signatures', ['name' => $name, 'html' => '<p>'.$name.'</p>'], $headers);
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('id');
    }

    /**
     * @param array<string, string> $headers
     */
    private function createAccount(array $headers, string $emailAddress): string
    {
        $this->client->jsonRequest('POST', '/api/v1/mail-accounts', [
            'emailAddress' => $emailAddress,
            'displayName' => $emailAddress,
            'username' => $emailAddress,
            'password' => 'app-password-1',
            'imap' => ['host' => 'imap.company.example', 'port' => 993, 'security' => 'tls'],
            'smtp' => ['host' => 'smtp.company.example', 'port' => 465, 'security' => 'tls'],
        ], $headers);
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('id');
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $changes
     */
    private function patch(array $headers, string $id, array $changes): void
    {
        $this->client->request(
            'PATCH',
            '/api/v1/signatures/'.$id,
            server: $headers + ['CONTENT_TYPE' => 'application/merge-patch+json'],
            content: json_encode($changes, \JSON_THROW_ON_ERROR),
        );
    }
}
