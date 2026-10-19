<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Draft;
use App\Entity\DraftAttachment;
use App\Outbox\CollectUpload;
use App\Outbox\UploadCollector;
use App\Tests\Support\InMemoryObjectStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Files attached to drafts: uploaded, or taken from a received message for a forward. They go out with the message and
 * into the Drafts folder copy, and a draft being sent stays (out of sight) until its message went out, so an undo or
 * a failure brings it back with its files. The test environment allows 4096 bytes of attachments per message.
 */
final class DraftAttachmentTest extends DatabaseTestCase
{
    use AuthenticatesUsers;

    private const string NOTES = "Q4 notes\nTravel stays, hiring moves to January.\n";

    private const string INVOICE = '%PDF-1.4 invoice 42';

    private string $accountId;

    private string $receivedAttachmentId;

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
        $this->kim = $this->userHeaders('kim@example.test', ['mail:read', 'mail:write', 'mail:send', 'settings:write']);
        $this->worker = $this->workerHeaders('sync:lease sync:ingest');

        $this->client->jsonRequest('POST', '/api/v1/mail-accounts', [
            'emailAddress' => 'kim@company.example',
            'displayName' => 'Kim',
            'username' => 'kim@company.example',
            'password' => 'app-password-1',
            'imap' => ['host' => 'imap.company.example', 'port' => 993, 'security' => 'tls'],
            'smtp' => ['host' => 'smtp.company.example', 'port' => 465, 'security' => 'tls'],
        ], $this->kim);
        self::assertResponseStatusCodeSame(201);
        $this->accountId = $this->responseString('id');

        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => 'w1'], $this->worker);
        self::assertResponseIsSuccessful();
        $this->receivedAttachmentId = $this->receiveInvoice();
    }

    public function testUploadsAFileThatReachesEveryDeviceWithTheDraft(): void
    {
        $id = $this->newDraft();

        $attachment = $this->upload($id, 'Q4 notes.txt', self::NOTES);

        self::assertSame(['filename' => 'Q4 notes.txt', 'contentType' => 'text/plain', 'size' => \strlen(self::NOTES), 'scanStatus' => 'pending'], array_intersect_key($attachment, ['filename' => 0, 'contentType' => 0, 'size' => 0, 'scanStatus' => 0]));
        $sha = hash('sha256', self::NOTES);
        self::assertSame(self::NOTES, $this->storage()->read('uploads/'.substr($sha, 0, 2).'/'.$sha));

        $this->client->request('GET', '/api/v1/drafts/'.$id, server: $this->kim);
        self::assertResponseIsSuccessful();
        $attachments = $this->decodedResponse()['attachments'] ?? null;
        self::assertIsArray($attachments);
        self::assertCount(1, $attachments);
        self::assertIsArray($attachments[0]);
        self::assertIsString($attachments[0]['url']);

        $this->client->request('GET', '/api/v1/sync/changes?since=0', server: $this->kim);
        $drafts = $this->decodedResponse()['drafts'] ?? null;
        self::assertIsArray($drafts);
        self::assertIsArray($drafts[0] ?? null);
        self::assertSame([['id' => $attachment['id'], 'filename' => 'Q4 notes.txt', 'contentType' => 'text/plain', 'size' => \strlen(self::NOTES), 'scanStatus' => 'pending']], $drafts[0]['attachments'], 'The delta has the files without links, which would expire on the device.');
    }

    public function testDownloadsAnAttachmentOnlyThroughItsSignedLink(): void
    {
        $id = $this->newDraft();
        $attachment = $this->upload($id, 'notes.txt', self::NOTES);
        self::assertIsString($attachment['url']);

        $this->client->request('GET', $attachment['url']);
        self::assertResponseIsSuccessful();
        self::assertSame(self::NOTES, $this->client->getInternalResponse()->getContent());
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));

        $this->client->request('GET', '/content/draft-attachments/'.$attachment['id']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testCleansUpTheNameAndRefusesEmptyFilesAndTooMuch(): void
    {
        $id = $this->newDraft();

        self::assertSame('evil.txt', $this->upload($id, "../../etc/\x07evil.txt", 'x')['filename']);

        $this->uploadRaw($id, 'empty.txt', '');
        self::assertResponseStatusCodeSame(422);

        $this->uploadRaw($id, 'big.bin', str_repeat('a', 4097));
        self::assertResponseStatusCodeSame(422);

        $this->upload($id, 'first.bin', str_repeat('b', 3000));
        $this->uploadRaw($id, 'second.bin', str_repeat('c', 1500));
        self::assertResponseStatusCodeSame(422, 'Together the attachments may not pass the limit.');
        self::assertStringContainsString('too large together', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/api/v1/drafts/'.$id.'/attachments', server: $this->kim);
        self::assertResponseStatusCodeSame(422, 'Without a file field.');
    }

    public function testOnlyTheOwnerAttachesAndRemoves(): void
    {
        $id = $this->newDraft();
        $attachment = $this->upload($id, 'notes.txt', self::NOTES);
        $lisa = $this->userHeaders('lisa@example.test', ['mail:read', 'mail:write']);

        $this->uploadRaw($id, 'mine.txt', 'x', $lisa);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('DELETE', '/api/v1/drafts/'.$id.'/attachments/'.$attachment['id'], server: $lisa);
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/api/v1/drafts/'.$id.'/forwarded-attachments', ['attachmentId' => $this->receivedAttachmentId], $lisa);
        self::assertResponseStatusCodeSame(404);

        $this->uploadRaw($id, 'read-only.txt', 'x', $this->userHeaders('kim@example.test', ['mail:read']));
        self::assertResponseStatusCodeSame(403);
    }

    public function testForwardsAReceivedFileOnceWithoutStoringItAgain(): void
    {
        $id = $this->newDraft(['kind' => 'forward']);

        $this->client->jsonRequest('POST', '/api/v1/drafts/'.$id.'/forwarded-attachments', ['attachmentId' => $this->receivedAttachmentId], $this->kim);
        self::assertResponseStatusCodeSame(201);
        $first = $this->decodedResponse();
        self::assertIsString($first['id'] ?? null);
        self::assertSame(['filename' => 'Factuur 42.pdf', 'contentType' => 'application/pdf', 'size' => \strlen(self::INVOICE)], array_intersect_key($first, ['filename' => 0, 'contentType' => 0, 'size' => 0]));

        $this->client->jsonRequest('POST', '/api/v1/drafts/'.$id.'/forwarded-attachments', ['attachmentId' => $this->receivedAttachmentId], $this->kim);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($first['id'], $this->decodedResponse()['id'] ?? null, 'Forwarding it again (a retry) keeps one.');

        $this->client->jsonRequest('POST', '/api/v1/drafts/'.$id.'/forwarded-attachments', ['attachmentId' => Uuid::v7()->toRfc4122()], $this->kim);
        self::assertResponseStatusCodeSame(422);

        $this->connection()->executeStatement("UPDATE attachment SET scan_status = 'infected' WHERE id = :id", ['id' => $this->receivedAttachmentId]);
        $this->client->jsonRequest('POST', '/api/v1/drafts/'.$this->newDraft().'/forwarded-attachments', ['attachmentId' => $this->receivedAttachmentId], $this->kim);
        self::assertResponseStatusCodeSame(422, 'Malware is not forwarded.');
    }

    public function testRemovesAnAttachmentAndCollectsAnUploadNoDraftRefersTo(): void
    {
        $first = $this->newDraft();
        $second = $this->newDraft();
        $kept = $this->upload($first, 'notes.txt', self::NOTES);
        $shared = $this->upload($second, 'notes.txt', self::NOTES);
        $sha = hash('sha256', self::NOTES);
        $key = 'uploads/'.substr($sha, 0, 2).'/'.$sha;

        $this->client->request('DELETE', '/api/v1/drafts/'.$second.'/attachments/'.$shared['id'], server: $this->kim);
        self::assertResponseStatusCodeSame(204);
        $this->collectQueuedUploads();
        self::assertTrue($this->storage()->has($key), 'The other draft still has the same file.');

        $this->client->request('DELETE', '/api/v1/drafts/'.$first.'/attachments/'.$kept['id'], server: $this->kim);
        self::assertResponseStatusCodeSame(204);
        $this->collectQueuedUploads();
        self::assertFalse($this->storage()->has($key));

        $this->client->request('GET', '/api/v1/drafts/'.$first, server: $this->kim);
        self::assertSame([], $this->decodedResponse()['attachments'] ?? null);
    }

    public function testDeletingADraftCollectsItsUploads(): void
    {
        $id = $this->newDraft();
        $this->upload($id, 'notes.txt', self::NOTES);

        $this->client->request('DELETE', '/api/v1/drafts/'.$id, server: $this->kim);
        self::assertResponseStatusCodeSame(204);
        $this->collectQueuedUploads();

        $sha = hash('sha256', self::NOTES);
        self::assertFalse($this->storage()->has('uploads/'.substr($sha, 0, 2).'/'.$sha));
    }

    public function testSendsTheFilesAndKeepsTheDraftOutOfSightUntilItWentOut(): void
    {
        $id = $this->newDraft(['to' => [['address' => 'anna@finance.example', 'name' => null]], 'subject' => 'Notes']);
        $this->upload($id, 'Q4 notes.txt', self::NOTES);
        $this->client->jsonRequest('POST', '/api/v1/drafts/'.$id.'/forwarded-attachments', ['attachmentId' => $this->receivedAttachmentId], $this->kim);
        self::assertResponseStatusCodeSame(201);
        $this->client->request('GET', '/api/v1/sync/changes?since=0', server: $this->kim);
        $cursor = $this->cursor();

        $sent = $this->send($id);

        $raw = $this->rawOf($sent);
        self::assertStringContainsString('filename="Q4 notes.txt"', $raw);
        self::assertStringContainsString(base64_encode(self::NOTES), str_replace("\r\n", '', $raw));
        self::assertStringContainsString('filename="Factuur 42.pdf"', $raw);
        $this->client->request('GET', '/api/v1/drafts/'.$id, server: $this->kim);
        self::assertResponseStatusCodeSame(404, 'A draft being sent is out of sight.');
        $this->client->request('GET', '/api/v1/sync/changes?since='.$cursor, server: $this->kim);
        self::assertContains(['type' => 'draft', 'id' => $id], (array) ($this->decodedResponse()['deleted'] ?? []));
        self::assertSame([], $this->decodedResponse()['drafts'] ?? null);
        $this->uploadRaw($id, 'late.txt', 'x');
        self::assertResponseStatusCodeSame(404, 'Its files cannot change on the way.');

        $claimed = $this->claimOutbox();
        self::assertCount(1, $claimed);
        $this->reportOutbox($sent, ['outcome' => 'sent']);
        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(Draft::class, Uuid::fromString($id)), 'Once it went out the draft goes.');
        self::assertSame(0, $this->entityManager()->getRepository(DraftAttachment::class)->count([]));
    }

    public function testAnUndoBringsTheDraftBackWithItsFiles(): void
    {
        $id = $this->newDraft(['to' => [['address' => 'anna@finance.example', 'name' => null]], 'subject' => 'Notes']);
        $this->upload($id, 'Q4 notes.txt', self::NOTES);
        $sent = $this->send($id, '+1 hour');
        $this->client->request('GET', '/api/v1/sync/changes?since=0', server: $this->kim);
        $cursor = $this->cursor();

        $this->client->jsonRequest('POST', '/api/v1/outgoing-messages/'.$sent.'/cancel', [], $this->kim);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/v1/drafts/'.$id, server: $this->kim);
        self::assertResponseIsSuccessful();
        $draft = $this->decodedResponse();
        self::assertSame(1, $draft['version'], 'Back as it was, so the device that sent it can go on writing it.');
        self::assertIsArray($draft['attachments']);
        self::assertCount(1, $draft['attachments']);
        $this->client->request('GET', '/api/v1/sync/changes?since='.$cursor, server: $this->kim);
        $drafts = $this->decodedResponse()['drafts'] ?? null;
        self::assertIsArray($drafts);
        self::assertSame($id, \is_array($drafts[0] ?? null) ? $drafts[0]['id'] : null, 'Every device gets it back.');

        $this->client->jsonRequest('PUT', '/api/v1/drafts/'.$id, ['mailAccountId' => $this->accountId, 'kind' => 'new', 'to' => [['address' => 'anna@finance.example', 'name' => null]], 'cc' => [], 'bcc' => [], 'subject' => 'Notes, edited', 'html' => '', 'quoteHtml' => '', 'expectedVersion' => 1], $this->kim);
        self::assertResponseIsSuccessful();
    }

    public function testAFailedMessageBringsTheDraftBack(): void
    {
        $id = $this->newDraft(['to' => [['address' => 'anna@finance.example', 'name' => null]], 'subject' => 'Notes']);
        $this->upload($id, 'Q4 notes.txt', self::NOTES);
        $sent = $this->send($id);
        $this->claimOutbox();

        $this->reportOutbox($sent, ['outcome' => 'failed', 'error' => 'Mailbox full']);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/v1/drafts/'.$id, server: $this->kim);
        self::assertResponseIsSuccessful();
        $attachments = $this->decodedResponse()['attachments'] ?? null;
        self::assertIsArray($attachments);
        self::assertCount(1, $attachments);
    }

    public function testDoesNotSendAFileWithMalwareNorADraftTwice(): void
    {
        $id = $this->newDraft(['to' => [['address' => 'anna@finance.example', 'name' => null]]]);
        $attachment = $this->upload($id, 'notes.txt', self::NOTES);
        $this->connection()->executeStatement("UPDATE draft_attachment SET scan_status = 'infected' WHERE id = :id", ['id' => $attachment['id']]);
        $this->entityManager()->clear();

        $this->client->jsonRequest('POST', '/api/v1/outgoing-messages', ['mailAccountId' => $this->accountId, 'to' => [['address' => 'anna@finance.example']], 'subject' => 'x', 'html' => '', 'draftId' => $id], $this->kim);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('contains malware', (string) $this->client->getResponse()->getContent());

        $this->client->request('DELETE', '/api/v1/drafts/'.$id.'/attachments/'.$attachment['id'], server: $this->kim);
        $this->send($id);
        $this->client->jsonRequest('POST', '/api/v1/outgoing-messages', ['mailAccountId' => $this->accountId, 'to' => [['address' => 'anna@finance.example']], 'subject' => 'x', 'html' => '', 'draftId' => $id], $this->kim);
        self::assertResponseStatusCodeSame(422, 'A draft on its way is not found again.');
    }

    public function testTheCopyInTheDraftsFolderHasTheFiles(): void
    {
        $id = $this->newDraft(['subject' => 'Notes']);
        $this->upload($id, 'Q4 notes.txt', self::NOTES);
        $this->connection()->executeStatement("UPDATE draft SET updated_at = now() - interval '1 minute' WHERE id = :id", ['id' => $id]);
        $this->entityManager()->clear();

        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/drafts/claim', ['workerId' => 'w1'], $this->worker);
        self::assertResponseIsSuccessful();
        $files = $this->decodedResponse()['files'] ?? null;
        self::assertIsArray($files);
        self::assertIsArray($files[0] ?? null);
        self::assertIsString($files[0]['rawObjectKey']);

        self::assertStringContainsString('filename="Q4 notes.txt"', $this->storage()->read($files[0]['rawObjectKey']));
    }

    public function testAFileGoneFromStorageStopsOnlyItsOwnDraft(): void
    {
        $broken = $this->newDraft(['to' => [['address' => 'anna@finance.example', 'name' => null]], 'subject' => 'Broken']);
        $this->upload($broken, 'gone.txt', 'soon gone');
        $sha = hash('sha256', 'soon gone');
        $this->storage()->delete('uploads/'.substr($sha, 0, 2).'/'.$sha);
        $fine = $this->newDraft(['subject' => 'Fine']);

        $this->client->jsonRequest('POST', '/api/v1/outgoing-messages', ['mailAccountId' => $this->accountId, 'to' => [['address' => 'anna@finance.example']], 'subject' => 'x', 'html' => '', 'draftId' => $broken], $this->kim);
        self::assertResponseStatusCodeSame(422);
        $detail = $this->decodedResponse()['detail'] ?? null;
        self::assertIsString($detail);
        self::assertStringContainsString('"gone.txt" is no longer available', $detail);

        $this->connection()->executeStatement("UPDATE draft SET updated_at = now() - interval '1 minute'");
        $this->entityManager()->clear();
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/drafts/claim', ['workerId' => 'w1'], $this->worker);
        self::assertResponseIsSuccessful();
        $files = $this->decodedResponse()['files'] ?? null;
        self::assertIsArray($files);
        self::assertSame([$fine], array_map(static fn (mixed $file): mixed => \is_array($file) ? $file['draftId'] : null, array_values($files)), 'The other draft is filed all the same.');
    }

    public function testAnUploadStoredButNeverReferredToIsCollected(): void
    {
        $sha = hash('sha256', 'orphan');
        $key = 'uploads/'.substr($sha, 0, 2).'/'.$sha;
        $this->storage()->put($key, 'orphan');

        static::getContainer()->get(UploadCollector::class)->collect($key);

        self::assertFalse($this->storage()->has($key));
        $this->expectException(\LogicException::class);
        static::getContainer()->get(UploadCollector::class)->collect('attachments/ab/'.$sha);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function newDraft(array $changes = []): string
    {
        $this->client->jsonRequest('POST', '/api/v1/drafts', $changes + ['mailAccountId' => $this->accountId, 'kind' => 'new', 'to' => [], 'cc' => [], 'bcc' => [], 'subject' => '', 'html' => '', 'quoteHtml' => ''], $this->kim);
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('id');
    }

    /**
     * @param array<string, string>|null $headers
     */
    private function uploadRaw(string $draftId, string $filename, string $content, ?array $headers = null): void
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        self::assertIsString($path);
        file_put_contents($path, $content);
        $file = new UploadedFile($path, $filename, null, null, true);
        $this->client->request('POST', '/api/v1/drafts/'.$draftId.'/attachments', files: ['file' => $file], server: ($headers ?? $this->kim) + ['HTTP_ACCEPT' => 'application/json']);
    }

    /**
     * @return array{id: string, url: ?string, filename: string, contentType: string, size: int, scanStatus: string}
     */
    private function upload(string $draftId, string $filename, string $content): array
    {
        $this->uploadRaw($draftId, $filename, $content);
        self::assertResponseStatusCodeSame(201);
        $response = $this->decodedResponse();
        self::assertIsString($response['id'] ?? null);
        self::assertIsString($response['filename'] ?? null);
        self::assertIsString($response['contentType'] ?? null);
        self::assertIsInt($response['size'] ?? null);
        self::assertIsString($response['scanStatus'] ?? null);
        $url = $response['url'] ?? null;
        self::assertTrue(null === $url || \is_string($url));

        return ['id' => $response['id'], 'url' => $url, 'filename' => $response['filename'], 'contentType' => $response['contentType'], 'size' => $response['size'], 'scanStatus' => $response['scanStatus']];
    }

    private function send(string $draftId, ?string $at = null): string
    {
        $this->client->jsonRequest('POST', '/api/v1/outgoing-messages', [
            'mailAccountId' => $this->accountId,
            'to' => [['address' => 'anna@finance.example']],
            'subject' => 'Notes',
            'html' => '<p>See attached.</p>',
            'draftId' => $draftId,
        ] + (null === $at ? [] : ['sendAt' => new \DateTimeImmutable($at)->format(\DATE_ATOM)]), $this->kim);
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('id');
    }

    private function rawOf(string $outgoingId): string
    {
        $key = $this->connection()->fetchOne('SELECT raw_object_key FROM outgoing_message WHERE id = :id', ['id' => $outgoingId]);
        self::assertIsString($key);

        return $this->storage()->read($key);
    }

    /**
     * @return list<mixed>
     */
    private function claimOutbox(): array
    {
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/outbox/claim', ['workerId' => 'w1'], $this->worker);
        self::assertResponseIsSuccessful();
        $messages = $this->decodedResponse()['messages'] ?? null;
        self::assertIsArray($messages);

        return array_values($messages);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function reportOutbox(string $outgoingId, array $result): void
    {
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/outbox/'.$outgoingId.'/result', ['workerId' => 'w1'] + $result, $this->worker);
    }

    private function cursor(): int
    {
        $cursor = $this->decodedResponse()['cursor'] ?? null;
        self::assertIsInt($cursor);

        return $cursor;
    }

    /** Runs the upload collections the requests queued, as the worker would after their delay. */
    private function collectQueuedUploads(): void
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof CollectUpload) {
                static::getContainer()->get(UploadCollector::class)->collect($message->objectKey);
            }
        }
        $transport->reset();
    }

    private function receiveInvoice(): string
    {
        $raw = "From: billing@finance.example\r\nSubject: Invoice 42\r\n\r\nYour invoice";
        $rawSha = hash('sha256', $raw);
        $rawKey = 'raw/'.substr($rawSha, 0, 2).'/'.$rawSha;
        $this->storage()->put($rawKey, $raw);
        $sha = hash('sha256', self::INVOICE);
        $this->storage()->put('attachments/'.substr($sha, 0, 2).'/'.$sha, self::INVOICE);

        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/folders', ['workerId' => 'w1', 'folders' => [
            ['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1],
        ]], $this->worker);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/messages', ['workerId' => 'w1', 'folder' => 'INBOX', 'messages' => [[
            'remoteUid' => '1',
            'rawObjectKey' => $rawKey,
            'size' => \strlen($raw),
            'receivedAt' => '2026-10-19T08:00:00+00:00',
            'messageId' => '<invoice-42@finance.example>',
            'subject' => 'Invoice 42',
            'from' => ['address' => 'billing@finance.example', 'name' => 'Finance'],
            'to' => [['address' => 'kim@company.example']],
            'snippet' => 'Your invoice',
            'attachments' => [
                ['filename' => 'Factuur 42.pdf', 'contentType' => 'application/pdf', 'size' => \strlen(self::INVOICE), 'sha256' => $sha],
            ],
        ]]], $this->worker);
        self::assertResponseIsSuccessful();

        $id = $this->connection()->fetchOne('SELECT id FROM attachment LIMIT 1');
        self::assertIsString($id);

        return $id;
    }

    private function storage(): InMemoryObjectStorage
    {
        return static::getContainer()->get(InMemoryObjectStorage::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
