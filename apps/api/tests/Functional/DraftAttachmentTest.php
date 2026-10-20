<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Draft;
use App\Entity\DraftAttachment;
use App\Entity\DraftCopyRemoval;
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
    use RunsScans;

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

        self::assertSame(['filename' => 'Q4 notes.txt', 'contentType' => 'text/plain', 'size' => \strlen(self::NOTES), 'scanStatus' => 'clean'], array_intersect_key($attachment, ['filename' => 0, 'contentType' => 0, 'size' => 0, 'scanStatus' => 0]));
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
        self::assertSame([['id' => $attachment['id'], 'filename' => 'Q4 notes.txt', 'contentType' => 'text/plain', 'size' => \strlen(self::NOTES), 'scanStatus' => 'clean', 'inline' => false, 'contentId' => null]], $drafts[0]['attachments'], 'The delta has the files without links, which would expire on the device.');
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
        self::assertResponseStatusCodeSame(200);
        $this->collectQueuedUploads();
        self::assertTrue($this->storage()->has($key), 'The other draft still has the same file.');

        $this->client->request('DELETE', '/api/v1/drafts/'.$first.'/attachments/'.$kept['id'], server: $this->kim);
        self::assertResponseStatusCodeSame(200);
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
        self::assertSame(2, $draft['version'], 'Back as it was (the file made it version 2), so the device that sent it can go on writing it.');
        self::assertIsArray($draft['attachments']);
        self::assertCount(1, $draft['attachments']);
        $this->client->request('GET', '/api/v1/sync/changes?since='.$cursor, server: $this->kim);
        $drafts = $this->decodedResponse()['drafts'] ?? null;
        self::assertIsArray($drafts);
        self::assertSame($id, \is_array($drafts[0] ?? null) ? $drafts[0]['id'] : null, 'Every device gets it back.');

        $this->client->jsonRequest('PUT', '/api/v1/drafts/'.$id, ['mailAccountId' => $this->accountId, 'kind' => 'new', 'to' => [['address' => 'anna@finance.example', 'name' => null]], 'cc' => [], 'bcc' => [], 'subject' => 'Notes, edited', 'html' => '', 'quoteHtml' => '', 'expectedVersion' => 2], $this->kim);
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

    public function testAFileChangeIsANewVersionOfTheDraft(): void
    {
        $id = $this->newDraft();

        $this->uploadRaw($id, 'notes.txt', self::NOTES);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, $this->decodedResponse()['draftVersion'] ?? null, 'The answer says which version the draft is now.');

        $this->client->jsonRequest('POST', '/api/v1/drafts/'.$id.'/forwarded-attachments', ['attachmentId' => $this->receivedAttachmentId], $this->kim);
        self::assertSame(3, $this->decodedResponse()['draftVersion'] ?? null);
        $forwarded = $this->responseString('id');
        $this->client->jsonRequest('POST', '/api/v1/drafts/'.$id.'/forwarded-attachments', ['attachmentId' => $this->receivedAttachmentId], $this->kim);
        self::assertSame(3, $this->decodedResponse()['draftVersion'] ?? null, 'Forwarding it again changes nothing.');

        $this->client->request('DELETE', '/api/v1/drafts/'.$id.'/attachments/'.$forwarded, server: $this->kim);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['draftVersion' => 4], $this->decodedResponse(), 'Removing one says the version too.');
        $this->client->request('GET', '/api/v1/drafts/'.$id, server: $this->kim);
        self::assertSame(4, $this->decodedResponse()['version'] ?? null);
    }

    public function testAFileAddedAfterFilingFilesTheDraftAgain(): void
    {
        $id = $this->newDraft(['subject' => 'Notes']);
        $this->connection()->executeStatement("UPDATE draft SET updated_at = now() - interval '1 minute' WHERE id = :id", ['id' => $id]);
        $this->entityManager()->clear();
        self::assertSame([$id], $this->claimFiling());
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/drafts/'.$id.'/filed', ['workerId' => 'w1', 'version' => 1, 'messageId' => '<draft-1@tresso>'], $this->worker);
        self::assertResponseIsSuccessful();

        $this->upload($id, 'notes.txt', self::NOTES);
        $this->connection()->executeStatement("UPDATE draft SET updated_at = now() - interval '1 minute', filing_claimed_until = NULL WHERE id = :id", ['id' => $id]);
        $this->entityManager()->clear();

        self::assertSame([$id], $this->claimFiling(), 'The copy in the Drafts folder gets the file too.');
    }

    public function testADraftBeingSentIsNotFiledNorChanged(): void
    {
        $id = $this->newDraft(['to' => [['address' => 'anna@finance.example', 'name' => null]], 'subject' => 'Notes']);
        $this->send($id, '+1 hour');
        $this->connection()->executeStatement("UPDATE draft SET updated_at = now() - interval '1 minute' WHERE id = :id", ['id' => $id]);
        $this->entityManager()->clear();

        self::assertSame([], $this->claimFiling(), 'It is on its way, not in the Drafts folder.');
        $this->client->jsonRequest('PUT', '/api/v1/drafts/'.$id, ['mailAccountId' => $this->accountId, 'kind' => 'new', 'to' => [], 'cc' => [], 'bcc' => [], 'subject' => 'Changed', 'html' => '', 'quoteHtml' => '', 'expectedVersion' => 1], $this->kim);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('DELETE', '/api/v1/drafts/'.$id, server: $this->kim);
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/drafts/'.$id.'/filed', ['workerId' => 'w1', 'version' => 1, 'messageId' => '<late@tresso>'], $this->worker);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->entityManager()->getRepository(DraftCopyRemoval::class)->count([]), 'A copy filed just before it was sent is taken away again.');
    }

    public function testRetriesThatRunOutBringTheDraftBackToEveryDevice(): void
    {
        $id = $this->newDraft(['to' => [['address' => 'anna@finance.example', 'name' => null]], 'subject' => 'Notes']);
        $this->upload($id, 'notes.txt', self::NOTES);
        $sent = $this->send($id);
        $this->claimOutbox();
        $this->connection()->executeStatement('UPDATE outgoing_message SET attempts = 6 WHERE id = :id', ['id' => $sent]);
        $this->entityManager()->clear();

        $this->reportOutbox($sent, ['outcome' => 'retry', 'error' => 'Connection lost']);
        self::assertResponseIsSuccessful();

        self::assertSame('failed', $this->connection()->fetchOne('SELECT status FROM outgoing_message WHERE id = :id', ['id' => $sent]));
        $this->client->request('GET', '/api/v1/sync/changes?since=0', server: $this->kim);
        $drafts = $this->decodedResponse()['drafts'] ?? null;
        self::assertIsArray($drafts);
        self::assertSame([$id], array_map(static fn (mixed $draft): mixed => \is_array($draft) ? $draft['id'] : null, $drafts), 'A device starting over gets it too.');
    }

    public function testRemovingTheAccountWhileSendingBringsTheDraftBack(): void
    {
        $id = $this->newDraft(['to' => [['address' => 'anna@finance.example', 'name' => null]], 'subject' => 'Notes']);
        $this->upload($id, 'notes.txt', self::NOTES);
        $this->send($id, '+1 hour');

        $this->client->request('DELETE', '/api/v1/mail-accounts/'.$this->accountId, server: $this->kim);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/v1/drafts/'.$id, server: $this->kim);
        self::assertResponseIsSuccessful();
        self::assertNull($this->decodedResponse()['mailAccountId'] ?? null, 'Back, without the account it was to go from.');
        $attachments = $this->decodedResponse()['attachments'] ?? null;
        self::assertIsArray($attachments);
        self::assertCount(1, $attachments);
    }

    public function testSendsANameInAnyScriptAndAnUnknownTypeAsBytes(): void
    {
        $id = $this->newDraft(['to' => [['address' => 'anna@finance.example', 'name' => null]], 'subject' => 'Notes']);
        $this->upload($id, 'Begroting één.txt', self::NOTES);
        $this->connection()->executeStatement("UPDATE attachment SET content_type = 'pdf; evil' WHERE id = :id", ['id' => $this->receivedAttachmentId]);
        $this->client->jsonRequest('POST', '/api/v1/drafts/'.$id.'/forwarded-attachments', ['attachmentId' => $this->receivedAttachmentId], $this->kim);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('application/octet-stream', $this->decodedResponse()['contentType'] ?? null);

        $raw = str_replace("\r\n ", ' ', $this->rawOf($this->send($id)));

        self::assertStringContainsStringIgnoringCase("filename*=utf-8''".rawurlencode('Begroting één.txt'), str_replace(' ', '', $raw));
        self::assertStringContainsString('Content-Type: application/octet-stream; name="Factuur 42.pdf"', $raw);
    }

    public function testATooLargeBodyIsReportedAsATooLargeFile(): void
    {
        $id = $this->newDraft();
        $this->client->jsonRequest('POST', '/api/v1/drafts/'.$id.'/attachments', ['file' => 'x'], $this->kim);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Send the file as multipart', (string) $this->client->getResponse()->getContent(), 'A body that is no form is not taken for a file too large.');

        // What PHP leaves of a request past post_max_size: no fields, no files, only its length.
        $this->client->request('POST', '/api/v1/drafts/'.$id.'/attachments', server: $this->kim + ['CONTENT_TYPE' => 'multipart/form-data; boundary=x', 'CONTENT_LENGTH' => '30000000', 'HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The file is too large', (string) $this->client->getResponse()->getContent());
    }

    public function testAForwardedInlineImageGoesOutInPlace(): void
    {
        $logo = $this->receiveLogo('logo@finance.example');
        $id = $this->newDraft(['to' => [['address' => 'anna@finance.example', 'name' => null]], 'kind' => 'reply', 'quoteHtml' => '<blockquote><img src="cid:logo@finance.example" alt="Logo"></blockquote>']);

        $this->client->jsonRequest('POST', '/api/v1/drafts/'.$id.'/forwarded-attachments', ['attachmentId' => $logo], $this->kim);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['inline' => true, 'contentId' => 'logo@finance.example'], array_intersect_key($this->decodedResponse(), ['inline' => 0, 'contentId' => 0]));

        // The text as the client sends it: what was written, then the quote.
        $raw = $this->rawOf($this->send($id, html: '<p>Got it.</p><blockquote><img src="cid:logo@finance.example" alt="Logo"></blockquote>'));
        self::assertStringContainsString('multipart/related', $raw, 'The image belongs with the text, not in the list of files.');
        self::assertStringContainsString('Content-ID: <logo@finance.example>', $raw);
        self::assertStringContainsString('Content-Disposition: inline', $raw);
        self::assertStringContainsString('src="cid:logo@finance.example"', $this->decodedHtmlOf($raw), 'The text still shows the image.');
    }

    public function testAnInlineImageTheTextNoLongerShowsGoesOutAsAFile(): void
    {
        $logo = $this->receiveLogo('logo@finance.example');
        $id = $this->newDraft(['to' => [['address' => 'anna@finance.example', 'name' => null]], 'kind' => 'reply']);
        $this->client->jsonRequest('POST', '/api/v1/drafts/'.$id.'/forwarded-attachments', ['attachmentId' => $logo], $this->kim);
        self::assertResponseStatusCodeSame(201);

        // Only a longer id that starts with its own is left in the text.
        $raw = $this->rawOf($this->send($id, html: '<p>The quote went.</p><img src="cid:logo@finance.example.other">'));

        self::assertStringNotContainsString('multipart/related', $raw, 'Nothing would show it: hidden, the recipient could not see it was sent.');
        self::assertStringContainsString('Content-Disposition: attachment; name=logo.png; filename=logo.png', $raw);
    }

    public function testAnInlineImageWhoseIdNoHeaderCanCarryGoesOutUnderOneOfTresso(): void
    {
        // Gmail's ids have no "@"; a Content-ID header needs one.
        $logo = $this->receiveLogo('ii_mgx81bq20');
        $id = $this->newDraft(['to' => [['address' => 'anna@finance.example', 'name' => null]], 'kind' => 'reply', 'subject' => 'Logo', 'quoteHtml' => '<img src="cid:ii_mgx81bq20">']);
        $this->client->jsonRequest('POST', '/api/v1/drafts/'.$id.'/forwarded-attachments', ['attachmentId' => $logo], $this->kim);
        self::assertResponseStatusCodeSame(201);

        // Shown by an inline style, which Symfony would not see: the image still goes with the text.
        $raw = $this->rawOf($this->send($id, html: '<p style="background-image:url(cid:ii_mgx81bq20)">Hero</p>'));
        self::assertStringContainsString('multipart/related', $raw);

        preg_match('~Content-ID: <([^>]+@inline\.tresso\.invalid)>~', $raw, $match);
        $contentId = $match[1] ?? null;
        self::assertIsString($contentId, 'It goes out under an id of its own.');
        self::assertStringContainsString('url(cid:'.$contentId.')', $this->decodedHtmlOf($raw));


        $kept = $this->newDraft(['kind' => 'reply', 'subject' => 'Kept', 'quoteHtml' => '<img src="cid:ii_mgx81bq20">']);
        $this->client->jsonRequest('POST', '/api/v1/drafts/'.$kept.'/forwarded-attachments', ['attachmentId' => $logo], $this->kim);
        $this->connection()->executeStatement("UPDATE draft SET updated_at = now() - interval '1 minute'");
        $this->entityManager()->clear();
        self::assertSame([$kept], $this->claimFiling(), 'The copy in the Drafts folder is made all the same.');
    }

    public function testADraftThatCannotBeFiledIsTriedAgainOnceItChanges(): void
    {
        $id = $this->newDraft(['subject' => 'Broken']);
        $this->upload($id, 'gone.txt', 'soon gone');
        $sha = hash('sha256', 'soon gone');
        $key = 'uploads/'.substr($sha, 0, 2).'/'.$sha;
        $this->storage()->delete($key);
        $this->connection()->executeStatement("UPDATE draft SET updated_at = now() - interval '1 minute'");
        $this->entityManager()->clear();
        self::assertSame([], $this->claimFiling());
        self::assertSame(Draft::UNFILEABLE, $this->connection()->fetchOne('SELECT filing_claimed_by FROM draft WHERE id = :id', ['id' => $id]));
        self::assertSame([], $this->claimFiling(), 'Not tried again on every claim.');

        $this->storage()->put($key, 'soon gone');
        $this->client->jsonRequest('PUT', '/api/v1/drafts/'.$id, ['mailAccountId' => $this->accountId, 'kind' => 'new', 'to' => [], 'cc' => [], 'bcc' => [], 'subject' => 'Fixed', 'html' => '', 'quoteHtml' => '', 'expectedVersion' => 2], $this->kim);
        self::assertResponseIsSuccessful();
        $this->connection()->executeStatement("UPDATE draft SET updated_at = now() - interval '1 minute'");
        $this->entityManager()->clear();

        self::assertSame([$id], $this->claimFiling());
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
        $id = $this->decodedResponse()['id'] ?? null;
        self::assertIsString($id);
        // The upload is scanned first; only then does it get a link.
        $this->runQueuedScans();

        $this->client->request('GET', '/api/v1/drafts/'.$draftId, server: $this->kim);
        self::assertResponseIsSuccessful();
        foreach ((array) ($this->decodedResponse()['attachments'] ?? []) as $response) {
            self::assertIsArray($response);
            if (($response['id'] ?? null) !== $id) {
                continue;
            }
            self::assertIsString($response['filename'] ?? null);
            self::assertIsString($response['contentType'] ?? null);
            self::assertIsInt($response['size'] ?? null);
            self::assertIsString($response['scanStatus'] ?? null);
            $url = $response['url'] ?? null;
            self::assertTrue(null === $url || \is_string($url));

            return ['id' => $id, 'url' => $url, 'filename' => $response['filename'], 'contentType' => $response['contentType'], 'size' => $response['size'], 'scanStatus' => $response['scanStatus']];
        }
        self::fail('The draft does not list the upload.');
    }

    private function send(string $draftId, ?string $at = null, string $html = '<p>See attached.</p>'): string
    {
        $this->client->jsonRequest('POST', '/api/v1/outgoing-messages', [
            'mailAccountId' => $this->accountId,
            'to' => [['address' => 'anna@finance.example']],
            'subject' => 'Notes',
            'html' => $html,
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

    /**
     * The drafts a worker gets to file now.
     *
     * @return list<mixed>
     */
    private function claimFiling(): array
    {
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/drafts/claim', ['workerId' => 'w1'], $this->worker);
        self::assertResponseIsSuccessful();
        $files = $this->decodedResponse()['files'] ?? null;
        self::assertIsArray($files);

        return array_map(static fn (mixed $file): mixed => \is_array($file) ? $file['draftId'] : null, array_values($files));
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
        $this->runQueuedScans();

        $id = $this->connection()->fetchOne('SELECT id FROM attachment LIMIT 1');
        self::assertIsString($id);

        return $id;
    }

    /** The HTML part of a message as sent, decoded. */
    private function decodedHtmlOf(string $raw): string
    {
        $message = new \ZBateson\MailMimeParser\MailMimeParser()->parse($raw, false);

        return (string) $message->getHtmlContent();
    }

    /** A message with a logo shown in its body, as a received inline image. */
    private function receiveLogo(string $contentId): string
    {
        $raw = "From: billing@finance.example\r\nSubject: Logo\r\n\r\nSee the logo";
        $rawSha = hash('sha256', $raw);
        $rawKey = 'raw/'.substr($rawSha, 0, 2).'/'.$rawSha;
        $this->storage()->put($rawKey, $raw);
        $png = "\x89PNG logo";
        $sha = hash('sha256', $png);
        $this->storage()->put('attachments/'.substr($sha, 0, 2).'/'.$sha, $png);

        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/messages', ['workerId' => 'w1', 'folder' => 'INBOX', 'messages' => [[
            'remoteUid' => '2',
            'rawObjectKey' => $rawKey,
            'size' => \strlen($raw),
            'receivedAt' => '2026-10-19T09:00:00+00:00',
            'messageId' => '<logo@finance.example>',
            'subject' => 'Logo',
            'from' => ['address' => 'billing@finance.example', 'name' => 'Finance'],
            'to' => [['address' => 'kim@company.example']],
            'snippet' => 'See the logo',
            'attachments' => [
                ['filename' => 'logo.png', 'contentType' => 'image/png', 'size' => \strlen($png), 'sha256' => $sha, 'inline' => true, 'contentId' => $contentId],
            ],
        ]]], $this->worker);
        self::assertResponseIsSuccessful();
        $this->runQueuedScans();

        $id = $this->connection()->fetchOne("SELECT id FROM attachment WHERE filename = 'logo.png'");
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
