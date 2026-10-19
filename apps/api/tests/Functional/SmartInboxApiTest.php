<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Classification\ReclassifyMail;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SmartInboxApiTest extends DatabaseTestCase
{
    use AuthenticatesUsers;

    private const array READ_WRITE = ['settings:read', 'settings:write'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapOAuthClients();
        $this->createUser('kim@example.test', 'Kim Visser');
        $this->createUser('lisa@example.test', 'Lisa Bakker');
    }

    public function testEveryUserStartsWithTheBuiltInSmartInboxes(): void
    {
        $this->client->request('GET', '/api/v1/smart-inboxes', server: $this->userHeaders('kim@example.test', self::READ_WRITE));

        self::assertResponseIsSuccessful();
        $list = $this->decodedResponse();
        self::assertSame(['todo', 'informational', 'notification', 'advertise', 'spam'], array_column($list, 'builtin'));
        self::assertSame(['do', 'know', 'know', 'know', 'none'], array_column($list, 'verdict'));
        self::assertSame([0, 1, 2, 3, 4], array_column($list, 'position'));
    }

    public function testCreateNormalizesTheListsAndGoesLast(): void
    {
        $headers = $this->userHeaders('kim@example.test', self::READ_WRITE);

        $this->client->jsonRequest('POST', '/api/v1/smart-inboxes', [
            'name' => '  Invoices ',
            'instructions' => 'Invoices and payment reminders',
            'includeSenders' => [' @Supplier.example', 'billing@hosting.example', '@supplier.example'],
            'excludeSenders' => ['news@supplier.example'],
            'verdict' => 'do',
        ], $headers);

        self::assertResponseStatusCodeSame(201);
        $body = $this->decodedResponse();
        self::assertSame('Invoices', $body['name'] ?? null);
        self::assertNull($body['builtin']);
        self::assertSame(['@supplier.example', 'billing@hosting.example'], $body['includeSenders'] ?? null);
        self::assertSame(['news@supplier.example'], $body['excludeSenders'] ?? null);
        self::assertSame('do', $body['verdict'] ?? null);
        self::assertSame(5, $body['position'] ?? null);
    }

    public function testEveryChangeQueuesAReclassification(): void
    {
        $headers = $this->userHeaders('kim@example.test', self::READ_WRITE);

        $this->client->jsonRequest('POST', '/api/v1/smart-inboxes', ['name' => 'Invoices', 'verdict' => 'do'], $headers);

        self::assertResponseStatusCodeSame(201);
        $queued = array_map(static fn ($envelope): object => $envelope->getMessage(), $this->classifyTransport()->getSent());
        self::assertCount(1, $queued);
        self::assertInstanceOf(ReclassifyMail::class, $queued[0]);
        self::assertNotNull($queued[0]->since);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidInput(): iterable
    {
        yield 'blank name' => [['name' => '  ', 'verdict' => 'do'], 'name'];
        yield 'long name' => [['name' => str_repeat('x', 81), 'verdict' => 'do'], 'name'];
        yield 'taken name' => [['name' => 'to DO', 'verdict' => 'do'], 'name'];
        yield 'unknown verdict' => [['name' => 'Invoices', 'verdict' => 'later'], 'verdict'];
        yield 'missing verdict' => [['name' => 'Invoices'], 'verdict'];
        yield 'bare domain' => [['name' => 'Invoices', 'verdict' => 'do', 'includeSenders' => ['supplier.example']], 'includeSenders[0]'];
        yield 'not a string' => [['name' => 'Invoices', 'verdict' => 'do', 'excludeSenders' => [42]], 'excludeSenders[0]'];
        yield 'on both lists' => [['name' => 'Invoices', 'verdict' => 'do', 'includeSenders' => ['@supplier.example'], 'excludeSenders' => ['@SUPPLIER.example']], 'excludeSenders'];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('invalidInput')]
    public function testInvalidInputIsRejected(array $input, string $path): void
    {
        $this->client->jsonRequest('POST', '/api/v1/smart-inboxes', $input, $this->userHeaders('kim@example.test', self::READ_WRITE));

        self::assertResponseStatusCodeSame(422);
        $violations = $this->decodedResponse()['violations'] ?? null;
        self::assertIsArray($violations);
        self::assertContains($path, array_column($violations, 'propertyPath'));
    }

    public function testUpdateChangesOnlyGivenFields(): void
    {
        $headers = $this->userHeaders('kim@example.test', self::READ_WRITE);
        $id = $this->create($headers, ['name' => 'Invoices', 'instructions' => 'Bills', 'includeSenders' => ['@supplier.example'], 'verdict' => 'do']);

        $this->patch($headers, $id, ['verdict' => 'delegate', 'excludeSenders' => ['news@supplier.example']]);

        self::assertResponseIsSuccessful();
        $body = $this->decodedResponse();
        self::assertSame('Invoices', $body['name'] ?? null);
        self::assertSame('Bills', $body['instructions'] ?? null);
        self::assertSame(['@supplier.example'], $body['includeSenders'] ?? null);
        self::assertSame(['news@supplier.example'], $body['excludeSenders'] ?? null);
        self::assertSame('delegate', $body['verdict'] ?? null);
    }

    public function testBuiltInSmartInboxesCanBeChangedButNotDeleted(): void
    {
        $headers = $this->userHeaders('kim@example.test', self::READ_WRITE);
        $todo = $this->builtin($headers, 'todo');

        $this->patch($headers, $todo, ['name' => 'Act on', 'excludeSenders' => ['@noise.example']]);
        self::assertResponseIsSuccessful();
        self::assertSame('Act on', $this->decodedResponse()['name'] ?? null);
        self::assertSame('todo', $this->decodedResponse()['builtin'] ?? null);

        $this->client->request('DELETE', '/api/v1/smart-inboxes/'.$todo, server: $headers);
        self::assertResponseStatusCodeSame(422);
    }

    public function testDeleteRemovesACustomSmartInbox(): void
    {
        $headers = $this->userHeaders('kim@example.test', self::READ_WRITE);
        $id = $this->create($headers, ['name' => 'Invoices', 'verdict' => 'do']);

        $this->client->request('DELETE', '/api/v1/smart-inboxes/'.$id, server: $headers);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/v1/smart-inboxes/'.$id, server: $headers);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUsersOnlySeeTheirOwnSmartInboxes(): void
    {
        $kim = $this->userHeaders('kim@example.test', self::READ_WRITE);
        $kims = $this->create($kim, ['name' => 'Invoices', 'verdict' => 'do']);

        $lisa = $this->userHeaders('lisa@example.test', self::READ_WRITE);
        $this->client->request('GET', '/api/v1/smart-inboxes', server: $lisa);
        self::assertNotContains('Invoices', array_column($this->decodedResponse(), 'name'));

        $this->client->request('GET', '/api/v1/smart-inboxes/'.$kims, server: $lisa);
        self::assertResponseStatusCodeSame(404);
        $this->patch($lisa, $kims, ['name' => 'Taken']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('DELETE', '/api/v1/smart-inboxes/'.$kims, server: $lisa);
        self::assertResponseStatusCodeSame(404);
    }

    public function testWritingNeedsTheSettingsWriteScope(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/smart-inboxes', ['name' => 'Invoices', 'verdict' => 'do'], $this->userHeaders('kim@example.test', ['settings:read']));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $input
     */
    private function create(array $headers, array $input): string
    {
        $this->client->jsonRequest('POST', '/api/v1/smart-inboxes', $input, $headers);
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('id');
    }

    /**
     * @param array<string, string> $headers
     */
    private function builtin(array $headers, string $builtin): string
    {
        $this->client->request('GET', '/api/v1/smart-inboxes', server: $headers);
        foreach ($this->decodedResponse() as $smartInbox) {
            if (\is_array($smartInbox) && ($smartInbox['builtin'] ?? null) === $builtin && \is_string($smartInbox['id'] ?? null)) {
                return $smartInbox['id'];
            }
        }
        self::fail('No built-in smart inbox '.$builtin);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $input
     */
    private function patch(array $headers, string $id, array $input): void
    {
        $this->client->request('PATCH', '/api/v1/smart-inboxes/'.$id, server: $headers + ['CONTENT_TYPE' => 'application/merge-patch+json'], content: json_encode($input, \JSON_THROW_ON_ERROR));
    }

    private function classifyTransport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.classify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
