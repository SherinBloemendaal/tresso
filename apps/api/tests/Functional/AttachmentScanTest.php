<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Command\RescanAttachmentsCommand;
use App\Entity\Attachment;
use App\Entity\ScanResult;
use App\Enum\ScanFailure;
use App\Outbox\UploadCollector;
use App\Repository\AttachmentRepository;
use App\Scan\AttachmentScans;
use App\Scan\PlaceContent;
use App\Scan\ScanAttachment;
use App\Scan\ScanOutcome;
use App\Scan\SweepPendingScans;
use App\Scan\SweepPendingScansHandler;
use App\Security\Crypto\KeyRing;
use App\Security\Crypto\UserKeys;
use App\Storage\BlobFormat;
use App\Storage\BlobKey;
use App\Storage\BlobKind;
use App\Storage\BlobVault;
use App\Tests\Support\FakeVirusScanner;
use App\Tests\Support\InMemoryObjectStorage;
use App\Tests\Support\RecordingMercureHub;
use App\Tests\Support\StatementHook;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Attachments are scanned by a Messenger job queued after the ingest commits. The tests run that queue themselves,
 * the way the worker would, against {@see FakeVirusScanner}; ClamdScannerTest covers the real clamd.
 */
final class AttachmentScanTest extends DatabaseTestCase
{
    use AuthenticatesUsers;
    use StoresMail;

    private const string INVOICE = "%PDF-1.7\ninvoice-bytes";
    private const string MALWARE = FakeVirusScanner::EICAR;

    /** @var array<string, string> */
    private array $kim;

    /** @var array<string, string> */
    private array $worker;

    private string $accountId;

    /** @var list<Envelope> scans queued by the ingest in setUp */
    private array $queued;

    /** @var list<Envelope> scans queued by the last upload */
    private array $uploadScans = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapOAuthClients();
        $this->createUser('kim@example.test', 'Kim');
        $this->kim = $this->userHeaders('kim@example.test', ['mail:read', 'mail:write', 'mail:send', 'settings:write']);

        $this->client->jsonRequest('POST', '/api/v1/mail-accounts', [
            'emailAddress' => 'kim@company.example',
            'displayName' => 'Work',
            'username' => 'kim@company.example',
            'password' => 'app-password-1',
            'imap' => ['host' => 'imap.company.example', 'port' => 993, 'security' => 'tls'],
            'smtp' => ['host' => 'smtp.company.example', 'port' => 465, 'security' => 'tls'],
        ], $this->kim);
        $this->accountId = $this->responseString('id');

        $this->worker = $this->workerHeaders('sync:lease sync:ingest');
        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => 'w1'], $this->worker);
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/folders', ['workerId' => 'w1', 'folders' => [
            ['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1],
        ]], $this->worker);
        self::assertResponseIsSuccessful();

        $this->queued = $this->ingest('1', '<invoice-42@finance.example>', ['invoice.pdf' => self::INVOICE, 'invoice.zip' => self::MALWARE]);
    }

    protected function tearDown(): void
    {
        StatementHook::reset();
        parent::tearDown();
    }

    public function testEveryNewAttachmentIsQueuedForAScan(): void
    {
        $queued = array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $this->queued);

        self::assertEquals([
            new ScanAttachment($this->attachment('invoice.pdf')->getId()->toRfc4122()),
            new ScanAttachment($this->attachment('invoice.zip')->getId()->toRfc4122()),
        ], $queued);
    }

    public function testMessageIsPendingUntilItsAttachmentsAreScanned(): void
    {
        self::assertSame('pending', $this->syncedMessage()['attachmentScan']);
    }

    public function testPendingAttachmentsGetNoLink(): void
    {
        $invoice = $this->listed('invoice.pdf');

        self::assertSame('pending', $invoice['scanStatus']);
        self::assertNull($invoice['url']);
    }

    public function testPendingAttachmentsAreNotServed(): void
    {
        $this->client->request('GET', $this->signedUrl($this->attachment('invoice.pdf')));

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Retry-After', '15');
    }

    public function testCleanAttachmentBecomesDownloadable(): void
    {
        $this->runScans($this->queued);

        $invoice = $this->listed('invoice.pdf');
        self::assertSame('clean', $invoice['scanStatus']);
        self::assertIsString($invoice['url']);
        $this->client->request('GET', $invoice['url']);
        self::assertResponseIsSuccessful();
    }

    public function testInfectedAttachmentIsQuarantined(): void
    {
        $this->runScans($this->queued);

        $zip = $this->listed('invoice.zip');
        self::assertSame('infected', $zip['scanStatus']);
        self::assertSame(FakeVirusScanner::SIGNATURE, $zip['scanSignature']);
        self::assertNull($zip['url']);
        $attachment = $this->attachment('invoice.zip');
        self::assertFalse($this->storage()->has($attachment->getObjectKey()));
        self::assertSame(self::MALWARE, $this->plaintext($attachment->getQuarantineKey()));
    }

    public function testQuarantinedContentIsNeverServed(): void
    {
        $url = $this->signedUrl($this->attachment('invoice.zip'));
        $this->runScans($this->queued);

        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(403);
    }

    public function testInfectedAttachmentFlagsTheMessageForItsClients(): void
    {
        $cursor = $this->cursor();
        $this->hub()->takePublished();

        $this->runScans($this->queued);

        $this->client->request('GET', '/api/v1/sync/changes?since='.$cursor, server: $this->kim);
        $messages = $this->decodedResponse()['messages'] ?? null;
        self::assertIsArray($messages);
        self::assertCount(1, $messages);
        self::assertIsArray($messages[0]);
        self::assertSame('infected', $messages[0]['attachmentScan']);
        self::assertNotSame([], $this->hub()->takePublished());
    }

    public function testFinishedFileIsAChangeWhileAnotherIsStillPending(): void
    {
        $cursor = $this->cursor();

        $this->runScans([$this->queued[0]]);

        $this->client->request('GET', '/api/v1/sync/changes?since='.$cursor, server: $this->kim);
        $messages = $this->decodedResponse()['messages'] ?? null;
        self::assertIsArray($messages);
        self::assertCount(1, $messages);
        self::assertIsArray($messages[0]);
        self::assertSame('pending', $messages[0]['attachmentScan']);
        self::assertSame('clean', $this->listed('invoice.pdf')['scanStatus']);
    }

    public function testForwardedCopyWaitsForTheScanAndTakesItsVerdict(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/drafts', ['mailAccountId' => $this->accountId, 'kind' => 'forward', 'to' => [], 'cc' => [], 'bcc' => [], 'subject' => 'Fwd: Invoice', 'html' => '', 'quoteHtml' => ''], $this->kim);
        $draftId = $this->responseString('id');
        $this->client->jsonRequest('POST', '/api/v1/drafts/'.$draftId.'/forwarded-attachments', ['attachmentId' => $this->attachment('invoice.zip')->getId()->toRfc4122()], $this->kim);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['scanStatus' => 'pending', 'url' => null], array_intersect_key($this->decodedResponse(), ['scanStatus' => 0, 'url' => 0]), 'The copy is not served before the scan.');
        $cursor = $this->cursor();

        $this->runScans($this->queued);

        $this->client->request('GET', '/api/v1/sync/changes?since='.$cursor, server: $this->kim);
        $drafts = $this->decodedResponse()['drafts'] ?? null;
        self::assertIsArray($drafts);
        self::assertCount(1, $drafts, 'The draft changed for its clients.');
        self::assertIsArray($drafts[0]);
        self::assertIsArray($drafts[0]['attachments']);
        self::assertIsArray($drafts[0]['attachments'][0]);
        self::assertSame('infected', $drafts[0]['attachments'][0]['scanStatus']);
    }

    public function testUploadsAreScannedAndMalwareLeavesTheUploadKey(): void
    {
        $draftId = $this->newDraft();

        $upload = $this->uploadFile($draftId, 'payload.zip', self::MALWARE);

        self::assertSame(['scanStatus' => 'pending', 'url' => null], array_intersect_key($upload, ['scanStatus' => 0, 'url' => 0]));
        $this->client->request('GET', $this->signedDraftUrl($upload['id']));
        self::assertResponseStatusCodeSame(409, 'Not served before the scan.');

        $this->runScans($this->uploadScans);

        $uploadKey = BlobKey::of(BlobKind::Uploads, $this->accountOwner($this->accountId), $this->fingerprintFor($this->accountId, self::MALWARE));
        self::assertFalse($this->storage()->has($uploadKey));
        self::assertTrue($this->storage()->has(Attachment::quarantineKey($this->fingerprintFor($this->accountId, self::MALWARE), $this->accountOwner($this->accountId))));
        $this->client->request('GET', $this->signedDraftUrl($upload['id']));
        self::assertResponseStatusCodeSame(403);
    }

    public function testReleasedContentGoesBackToEveryKey(): void
    {
        $this->runScans($this->queued);
        $this->uploadFile($this->newDraft(), 'copy.zip', self::MALWARE);
        $this->runScans($this->uploadScans);
        $this->scanner()->cleared = self::MALWARE;

        $this->rescan(['--all' => true]);

        $fingerprint = $this->fingerprintFor($this->accountId, self::MALWARE);
        $owner = $this->accountOwner($this->accountId);
        self::assertTrue($this->storage()->has(Attachment::objectKeyFor($fingerprint, $owner)));
        self::assertTrue($this->storage()->has(BlobKey::of(BlobKind::Uploads, $owner, $fingerprint)));
        self::assertFalse($this->storage()->has(Attachment::quarantineKey($fingerprint, $owner)));
    }

    public function testQuarantineIsNeverCollectedAsAnUpload(): void
    {
        $this->expectException(\LogicException::class);

        static::getContainer()->get(UploadCollector::class)->collect(Attachment::quarantineKey($this->fingerprintFor($this->accountId, self::MALWARE), $this->accountOwner($this->accountId)));
    }

    public function testSendingWaitsForTheScan(): void
    {
        $draftId = $this->newDraft();
        $this->uploadFile($draftId, 'notes.txt', 'Q4 notes');

        $this->client->jsonRequest('POST', '/api/v1/outgoing-messages', $this->outgoing($draftId), $this->kim);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('is still being scanned', (string) $this->client->getResponse()->getContent());
    }

    public function testFileFoundInfectedAfterSendingIsNotSent(): void
    {
        $draftId = $this->newDraft();
        $this->uploadFile($draftId, 'notes.txt', 'Q4 notes');
        $this->runScans($this->uploadScans);
        $this->client->jsonRequest('POST', '/api/v1/outgoing-messages', $this->outgoing($draftId), $this->kim);
        self::assertResponseStatusCodeSame(201);
        $outgoingId = $this->responseString('id');
        $this->scanner()->alsoInfected = ['Q4 notes'];
        $this->rescan(['--all' => true]);

        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/outbox/claim', ['workerId' => 'w1'], $this->worker);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->decodedResponse()['messages'] ?? null);
        $failed = $this->connection()->fetchAssociative('SELECT status, last_error FROM outgoing_message WHERE id = :id', ['id' => $outgoingId]);
        self::assertIsArray($failed);
        self::assertSame('failed', $failed['status']);
        self::assertIsString($failed['last_error']);
        self::assertStringContainsString('contains malware', $failed['last_error']);
    }

    public function testFileThatCouldNotBeScannedGoesOutOnlyAfterConfirmation(): void
    {
        $this->scanner()->enabled = false;
        $this->runScans($this->queued);
        $draftId = $this->newDraft();

        $this->client->jsonRequest('POST', '/api/v1/drafts/'.$draftId.'/forwarded-attachments', ['attachmentId' => $this->attachment('invoice.zip')->getId()->toRfc4122()], $this->kim);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['scanStatus' => 'failed', 'access' => 'confirm', 'url' => null], array_intersect_key($this->decodedResponse(), ['scanStatus' => 0, 'access' => 0, 'url' => 0]));

        $this->client->jsonRequest('POST', '/api/v1/outgoing-messages', $this->outgoing($draftId), $this->kim);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Confirm that you want to send it anyway', (string) $this->client->getResponse()->getContent());

        $this->client->jsonRequest('POST', '/api/v1/outgoing-messages', ['acceptUnscanned' => true] + $this->outgoing($draftId), $this->kim);
        self::assertResponseStatusCodeSame(201);
    }

    public function testInfectedFileIsNeverSentEvenWhenConfirmed(): void
    {
        $this->runScans($this->queued);
        $draftId = $this->newDraft();
        $this->uploadFile($draftId, 'payload.zip', self::MALWARE);
        $this->runScans($this->uploadScans);

        $this->client->jsonRequest('POST', '/api/v1/outgoing-messages', ['acceptUnscanned' => true] + $this->outgoing($draftId), $this->kim);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('contains malware', (string) $this->client->getResponse()->getContent());
    }

    public function testVerdictIsCachedByContent(): void
    {
        $this->runScans($this->queued);
        $result = $this->entityManager()->find(ScanResult::class, $this->cacheKeyOf(self::MALWARE));
        self::assertInstanceOf(ScanResult::class, $result);
        self::assertSame('ClamAV 1.5.1/27800', $result->getEngine());

        $queued = $this->ingest('2', '<reminder@finance.example>', ['reminder.zip' => self::MALWARE]);
        $this->runScans($queued);

        self::assertCount(2, $this->scanner()->scanned, 'The same content is not scanned twice.');
        self::assertSame('infected', $this->listed('reminder.zip', '2')['scanStatus']);
    }

    public function testAttachmentOverTheSizeLimitIsNotScanned(): void
    {
        $this->scanner()->maxSize = 10;

        $this->runScans($this->queued);

        $invoice = $this->listed('invoice.pdf');
        self::assertSame('failed', $invoice['scanStatus']);
        self::assertSame('too_large', $invoice['scanFailure']);
        self::assertSame([], $this->scanner()->scanned);
    }

    public function testUnscannedAttachmentDownloadsOnlyAfterConfirmation(): void
    {
        $this->scanner()->enabled = false;
        $plain = $this->signedUrl($this->attachment('invoice.zip'));
        $this->runScans($this->queued);

        $zip = $this->listed('invoice.zip');
        self::assertSame(['scanStatus' => 'failed', 'access' => 'confirm', 'scanFailure' => 'disabled', 'url' => null], array_intersect_key($zip, ['scanStatus' => 0, 'access' => 0, 'scanFailure' => 0, 'url' => 0]));
        self::assertIsString($zip['id']);
        self::assertSame('failed', $this->syncedMessage()['attachmentScan']);
        $this->client->request('GET', $plain);
        self::assertResponseStatusCodeSame(403, 'A link without the confirmation is refused.');

        $this->client->jsonRequest('POST', '/api/v1/attachments/'.$zip['id'].'/unscanned-link', [], $this->kim);
        self::assertResponseStatusCodeSame(422, 'The user has to confirm.');

        $this->client->jsonRequest('POST', '/api/v1/attachments/'.$zip['id'].'/unscanned-link', ['confirmed' => true], $this->kim);
        self::assertResponseIsSuccessful();
        $url = $this->responseString('url');
        self::assertStringContainsString('unscanned=1', $url);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
    }

    public function testConfirmationCannotBeAddedToALinkByHand(): void
    {
        $this->scanner()->enabled = false;
        $plain = $this->signedUrl($this->attachment('invoice.zip'));
        $this->runScans($this->queued);

        $this->client->request('GET', $plain.'&unscanned=1');

        self::assertResponseStatusCodeSame(403);
    }

    public function testUnscannedLinksAreOnlyForTheirOwner(): void
    {
        $this->scanner()->enabled = false;
        $this->runScans($this->queued);
        $this->createUser('lisa@example.test', 'Lisa');

        $this->client->jsonRequest('POST', '/api/v1/attachments/'.$this->attachment('invoice.zip')->getId()->toRfc4122().'/unscanned-link', ['confirmed' => true], $this->userHeaders('lisa@example.test', ['mail:read']));

        self::assertResponseStatusCodeSame(404);
    }

    public function testNoLinkForPendingOrInfectedFilesEvenWhenConfirmed(): void
    {
        $zip = $this->attachment('invoice.zip')->getId()->toRfc4122();
        $this->client->jsonRequest('POST', '/api/v1/attachments/'.$zip.'/unscanned-link', ['confirmed' => true], $this->kim);
        self::assertResponseStatusCodeSame(409);

        $this->runScans($this->queued);
        $this->client->jsonRequest('POST', '/api/v1/attachments/'.$zip.'/unscanned-link', ['confirmed' => true], $this->kim);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnscannedDraftFileDownloadsOnlyAfterConfirmation(): void
    {
        $this->scanner()->enabled = false;
        $draftId = $this->newDraft();
        $upload = $this->uploadFile($draftId, 'notes.txt', 'Q4 notes');
        $this->runScans($this->uploadScans);
        self::assertIsString($upload['id']);

        $this->client->jsonRequest('POST', '/api/v1/draft-attachments/'.$upload['id'].'/unscanned-link', ['confirmed' => true], $this->kim);

        self::assertResponseIsSuccessful();
        $this->client->request('GET', $this->responseString('url'));
        self::assertResponseIsSuccessful();
    }

    public function testUnreachableScannerFailsTheScanForARetry(): void
    {
        $this->scanner()->unavailable = true;

        try {
            $this->runScans([$this->queued[0]]);
            self::fail('The scan was expected to fail, for Messenger to retry it.');
        } catch (HandlerFailedException) {
        }

        self::assertSame('pending', $this->listed('invoice.pdf')['scanStatus']);
    }

    public function testClamdErrorsAreRetriedThenMarkedUnscannable(): void
    {
        $this->scanner()->failing = true;

        $this->giveUp($this->queued[0]);

        $invoice = $this->listed('invoice.pdf');
        self::assertSame('failed', $invoice['scanStatus']);
        self::assertSame('unscannable', $invoice['scanFailure']);
    }

    public function testFailedScanCanBeRetried(): void
    {
        $this->scanner()->unavailable = true;
        $this->giveUp($this->queued[0]);
        $this->scanner()->unavailable = false;

        $this->runScans([$this->queued[0]]);

        self::assertSame('clean', $this->listed('invoice.pdf')['scanStatus'], 'messenger:failed:retry sends the same message again.');
    }

    public function testFailureNeverReplacesAVerdictItArrivesAfter(): void
    {
        // The job has the attachment loaded while it is pending; meanwhile another job for the same content finishes.
        self::assertSame('pending', $this->attachment('invoice.pdf')->getScanStatus()->value);
        $this->connection()->executeStatement("UPDATE attachment SET scan_status = 'clean' WHERE filename = 'invoice.pdf'");
        $this->scanner()->unavailable = true;

        $this->giveUp($this->queued[0]);

        self::assertSame('clean', $this->connection()->fetchOne("SELECT scan_status FROM attachment WHERE filename = 'invoice.pdf'"));
    }

    public function testRescanThatGivesUpWaitsInTheFailureQueue(): void
    {
        $this->runScans($this->queued);
        $this->scanner()->unavailable = true;
        $failed = static::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(InMemoryTransport::class, $failed);

        $this->giveUp(new Envelope(new ScanAttachment($this->attachment('invoice.zip')->getId()->toRfc4122(), rescan: true)));

        self::assertCount(1, $failed->getSent(), 'Kept for messenger:failed:retry, not dropped.');
    }

    public function testTheVerdictCacheServesOtherUsersWithoutLinkingThem(): void
    {
        $this->runScans($this->queued);
        $this->createUser('lisa@example.test', 'Lisa');
        $this->client->jsonRequest('POST', '/api/v1/mail-accounts', [
            'emailAddress' => 'lisa@company.example',
            'displayName' => 'Work',
            'username' => 'lisa@company.example',
            'password' => 'app-password-2',
            'imap' => ['host' => 'imap.company.example', 'port' => 993, 'security' => 'tls'],
            'smtp' => ['host' => 'smtp.company.example', 'port' => 465, 'security' => 'tls'],
        ], $this->userHeaders('lisa@example.test', ['settings:write']));
        $lisaAccount = $this->responseString('id');
        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => 'w1'], $this->worker);
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$lisaAccount.'/folders', ['workerId' => 'w1', 'folders' => [
            ['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1],
        ]], $this->worker);
        self::assertResponseIsSuccessful();

        $this->runScans($this->ingest('7', '<same-file@finance.example>', ['copy.zip' => self::MALWARE], $lisaAccount));

        self::assertCount(2, $this->scanner()->scanned, 'Lisa\'s copy took the cached verdict.');
        /** @var array<string, string> $rows */
        $rows = $this->connection()->fetchAllKeyValue("SELECT filename, sha256 FROM attachment WHERE filename IN ('invoice.zip', 'copy.zip')");
        self::assertCount(2, $rows);
        self::assertNotSame($rows['invoice.zip'], $rows['copy.zip'], 'The same file has unrelated names at two users.');
        self::assertSame('infected', $this->connection()->fetchOne("SELECT scan_status FROM attachment WHERE filename = 'copy.zip'"));
        /** @var list<string> $cacheKeys */
        $cacheKeys = $this->connection()->fetchFirstColumn('SELECT content_key FROM scan_result');
        self::assertSame([], array_intersect($cacheKeys, [...array_values($rows), hash('sha256', self::MALWARE)]), 'Nothing in the cache matches an attachment or the plain hash.');
    }

    public function testAnAttachmentThatIsNotWhatItsFingerprintSaysIsNotScannedOrServed(): void
    {
        $queued = $this->ingest('2', '<swapped@finance.example>', ['swapped.zip' => 'an innocent file']);
        // A broken or misbehaving worker stored other content under that file's name, encrypted all the same.
        $owner = $this->accountOwner($this->accountId);
        $fingerprint = $this->fingerprintFor($this->accountId, 'an innocent file');
        $sealed = fopen('php://memory', 'w+b');
        self::assertIsResource($sealed);
        BlobFormat::encrypt(UserKeys::blobKey(static::getContainer()->get(UserKeys::class)->current($owner)->key), 1, $owner, $fingerprint, [self::MALWARE], $sealed);
        rewind($sealed);
        $this->storage()->put(Attachment::objectKeyFor($fingerprint, $owner), (string) stream_get_contents($sealed));

        $this->runScans($queued);

        $swapped = $this->listed('swapped.zip', '2');
        self::assertSame(['failed', 'unscannable', null], [$swapped['scanStatus'], $swapped['scanFailure'], $swapped['url']]);
        self::assertNotContains(self::MALWARE, $this->scanner()->scanned, 'Not handed to the scanner as if it were that file.');
    }

    public function testScansContentStoredBeforeEncryption(): void
    {
        $sha = hash('sha256', self::MALWARE);
        $this->storage()->put(Attachment::objectKeyFor($sha, null), self::MALWARE);
        $this->connection()->executeStatement("UPDATE attachment SET sha256 = :sha, key_version = NULL WHERE filename = 'invoice.zip'", ['sha' => $sha]);

        $this->runScans($this->queued);

        self::assertSame('infected', $this->listed('invoice.zip')['scanStatus']);
        self::assertFalse($this->storage()->has(Attachment::objectKeyFor($sha, null)));
        self::assertSame(self::MALWARE, $this->storage()->read(Attachment::quarantineKey($sha, null)), 'Plaintext content stays plaintext until it is re-encrypted.');
    }

    public function testContentMovesUnderTheVerdictLock(): void
    {
        $sha = $this->fingerprintFor($this->accountId, self::MALWARE);
        $params = $this->connection()->getParams();
        self::assertIsString($params['host'] ?? null);
        self::assertIsString($params['user'] ?? null);
        self::assertIsString($params['password'] ?? null);
        self::assertIsString($params['dbname'] ?? null);
        $other = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => $params['host'], 'port' => \is_int($params['port'] ?? null) ? $params['port'] : 5432, 'user' => $params['user'], 'password' => $params['password'], 'dbname' => $params['dbname']]);
        $lockedWhileMoving = [];
        $this->storage()->whileMoving = static function () use ($other, $sha, &$lockedWhileMoving): void {
            $lockedWhileMoving[] = !$other->fetchOne("SELECT pg_try_advisory_lock(hashtextextended('scan:' || :sha, 0))", ['sha' => $sha]);
        };

        $this->runScans($this->queued);

        self::assertSame([true], $lockedWhileMoving, 'A verdict or another placement for the content waits until the move is done.');
        $other->close();
    }

    public function testCachedVerdictOfOlderSignaturesIsNotReused(): void
    {
        $this->runScans($this->queued);
        $this->scanner()->engine = 'ClamAV 1.5.1/27801';

        $this->runScans($this->ingest('2', '<reminder@finance.example>', ['reminder.zip' => self::MALWARE]));

        self::assertCount(3, $this->scanner()->scanned, 'New signatures scan the content again.');
        $result = $this->entityManager()->find(ScanResult::class, $this->cacheKeyOf(self::MALWARE));
        self::assertInstanceOf(ScanResult::class, $result);
        self::assertSame('ClamAV 1.5.1/27801', $result->getEngine());
    }

    public function testInfectedVerdictHoldsWhileStorageIsDown(): void
    {
        $this->storage()->broken = true;
        $bus = static::getContainer()->get(MessageBusInterface::class);
        $events = static::getContainer()->get(EventDispatcherInterface::class);
        foreach ($this->queued as $envelope) {
            $events->dispatch(new WorkerMessageHandledEvent($bus->dispatch($envelope->with(new ReceivedStamp('scan'))), 'scan'));
        }
        $this->entityManager()->clear();
        $placements = $this->takeQueued();

        $zip = $this->listed('invoice.zip');
        self::assertSame('infected', $zip['scanStatus'], 'The verdict is committed before the content moves.');
        self::assertNull($zip['url']);
        self::assertEquals([new PlaceContent($this->fingerprintFor($this->accountId, self::MALWARE))], array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $placements));
        try {
            $this->runScans($placements);
            self::fail('Moving the content was expected to fail, for Messenger to retry it.');
        } catch (HandlerFailedException) {
        }

        $this->storage()->broken = false;
        $this->runScans($placements);

        $attachment = $this->attachment('invoice.zip');
        self::assertFalse($this->storage()->has($attachment->getObjectKey()));
        self::assertTrue($this->storage()->has($attachment->getQuarantineKey()));
    }

    public function testFirstScanOnlyChangesAttachmentsStillWaiting(): void
    {
        $this->runScans($this->queued);
        $queued = $this->ingest('2', '<reminder@finance.example>', ['reminder.pdf' => self::INVOICE]);
        $cursor = $this->cursor();

        $this->runScans($queued);

        self::assertSame([$this->syncedMessage('2')['id']], array_column($this->changedMessages($cursor), 'id'), 'The earlier message with the same file is not touched.');
    }

    public function testSameFileStoredDuringAVerdictIsSettledByItsOwnScan(): void
    {
        $this->ingest('2', '<later@finance.example>', []);
        $laterMessage = $this->syncedMessage('2')['id'];
        self::assertIsString($laterMessage);
        $lateRow = Uuid::v7()->toRfc4122();
        // Between locking the affected messages and writing the verdict, the same file arrives in another message.
        StatementHook::before('UPDATE attachment SET scan_status', function () use ($laterMessage, $lateRow): void {
            $this->connection()->executeStatement(
                "INSERT INTO attachment (id, mail_account_id, message_id, filename, content_type, size, sha256, key_version, inline, scan_status, scan_requested_at)
                 VALUES (:id, :account, :message, 'copy.pdf', 'application/pdf', :size, :sha, 1, false, 'pending', NOW())",
                ['id' => $lateRow, 'account' => $this->accountId, 'message' => $laterMessage, 'size' => \strlen(self::INVOICE), 'sha' => $this->fingerprintFor($this->accountId, self::INVOICE)],
            );
            $this->connection()->executeStatement("UPDATE message SET attachment_scan = 'pending', has_attachments = true WHERE id = :id", ['id' => $laterMessage]);
        });

        $this->runScans([$this->queued[0]]);
        self::assertSame('pending', $this->connection()->fetchOne('SELECT scan_status FROM attachment WHERE id = :id', ['id' => $lateRow]), 'Left for its own scan.');

        $this->runScans([new Envelope(new ScanAttachment($lateRow))]);

        self::assertSame('clean', $this->connection()->fetchOne('SELECT scan_status FROM attachment WHERE id = :id', ['id' => $lateRow]));
        self::assertSame('clean', $this->syncedMessage('2')['attachmentScan'], 'Its message is not stuck at scanning.');
    }

    public function testRescanWithTheSameVerdictsChangesNothing(): void
    {
        $this->runScans($this->queued);
        $cursor = $this->cursor();

        $this->rescan(['--all' => true]);

        self::assertSame([], $this->changedMessages($cursor));
    }

    public function testSweeperQueuesScansThatGotLost(): void
    {
        $this->connection()->executeStatement("UPDATE attachment SET scan_requested_at = NOW() - INTERVAL '1 hour'");
        $sweep = static::getContainer()->get(SweepPendingScansHandler::class);

        $sweep(new SweepPendingScans());
        $first = $this->takeQueued();
        $sweep(new SweepPendingScans());

        self::assertCount(2, $first, 'One scan per pending content.');
        self::assertSame([], $this->takeQueued(), 'A sweep claims what it queued.');
        $this->runScans($first);
        self::assertSame('clean', $this->listed('invoice.pdf')['scanStatus']);
    }

    public function testSweeperLeavesRecentlyQueuedScansAlone(): void
    {
        static::getContainer()->get(SweepPendingScansHandler::class)(new SweepPendingScans());

        self::assertSame([], $this->takeQueued());
    }

    public function testAttachmentIsMarkedFailedOnceRetriesRunOut(): void
    {
        $this->scanner()->unavailable = true;

        $this->giveUp($this->queued[0]);

        $invoice = $this->listed('invoice.pdf');
        self::assertSame('failed', $invoice['scanStatus']);
        self::assertSame('unavailable', $invoice['scanFailure']);
    }

    public function testRescanReleasesAFalsePositive(): void
    {
        $this->runScans($this->queued);
        $this->scanner()->cleared = self::MALWARE;

        $this->rescan(['--all' => true]);

        $zip = $this->listed('invoice.zip');
        self::assertSame('clean', $zip['scanStatus']);
        self::assertIsString($zip['url']);
        $attachment = $this->attachment('invoice.zip');
        self::assertSame(self::MALWARE, $this->plaintext($attachment->getObjectKey()));
        self::assertFalse($this->storage()->has($attachment->getQuarantineKey()));
    }

    public function testRescanThatCannotFinishKeepsTheQuarantine(): void
    {
        $this->runScans($this->queued);
        $this->scanner()->unavailable = true;

        $this->giveUp(new Envelope(new ScanAttachment($this->attachment('invoice.zip')->getId()->toRfc4122(), rescan: true)));

        self::assertSame('infected', $this->listed('invoice.zip')['scanStatus']);
    }

    public function testRescansWaitInTheirOwnQueueAndCanResume(): void
    {
        $this->runScans($this->queued);
        $hashes = [$this->fingerprintFor($this->accountId, self::INVOICE), $this->fingerprintFor($this->accountId, self::MALWARE)];
        sort($hashes);

        $tester = $this->commandTester();
        $tester->execute(['--all' => true, '--after' => $hashes[0]]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([], $this->takeQueued());
        $queued = $this->takeQueued(RescanAttachmentsCommand::TRANSPORT);
        self::assertCount(1, $queued, 'Only content after the resume point.');
        $message = $queued[0]->getMessage();
        self::assertInstanceOf(ScanAttachment::class, $message);
        self::assertTrue($message->rescan);
    }

    public function testRescanSkipsTheCache(): void
    {
        $this->runScans($this->queued);

        $this->rescan(['--user' => ['kim@example.test']]);

        self::assertCount(4, $this->scanner()->scanned);
    }

    public function testRescanCanBeLimitedToAStatus(): void
    {
        $this->runScans($this->queued);

        $this->rescan(['--all' => true, '--status' => ['infected']]);

        self::assertSame([self::INVOICE, self::MALWARE, self::MALWARE], $this->scanner()->scanned);
    }

    public function testRescanNeedsUsersOrAll(): void
    {
        $tester = $this->commandTester();

        self::assertSame(Command::INVALID, $tester->execute([]));
    }

    public function testRescanRejectsUnknownStatuses(): void
    {
        $tester = $this->commandTester();

        self::assertSame(Command::INVALID, $tester->execute(['--all' => true, '--status' => ['quarantined']]));
    }

    /**
     * @param array<string, string> $files filename => content
     *
     * @return list<Envelope> the scans the ingest queued
     */
    private function ingest(string $uid, string $messageId, array $files, ?string $accountId = null): array
    {
        $accountId ??= $this->accountId;
        $raw = "Message-ID: {$messageId}\r\nSubject: Invoice\r\n\r\nSee attached.\r\n";
        $rawKey = $this->storeAsWorker($accountId, BlobKind::Raw, $raw);

        $attachments = [];
        foreach ($files as $filename => $content) {
            $this->storeAsWorker($accountId, BlobKind::Attachments, $content);
            $attachments[] = ['filename' => $filename, 'contentType' => 'application/octet-stream', 'size' => \strlen($content), 'fingerprint' => $this->fingerprintFor($accountId, $content)];
        }

        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$accountId.'/messages', ['workerId' => 'w1', 'folder' => 'INBOX', 'messages' => [[
            'remoteUid' => $uid,
            'rawObjectKey' => $rawKey,
            'keyVersion' => 1,
            'size' => \strlen($raw),
            'receivedAt' => '2026-10-19T08:00:00+00:00',
            'messageId' => $messageId,
            'subject' => 'Invoice',
            'from' => ['address' => 'billing@finance.example', 'name' => 'Finance'],
            'to' => [['address' => 'kim@company.example']],
            'snippet' => 'See attached.',
            'attachments' => $attachments,
        ]]], $this->worker);
        self::assertResponseIsSuccessful();

        return $this->takeQueued();
    }

    /**
     * @return list<Envelope>
     */
    private function takeQueued(string $transportName = 'scan'): array
    {
        $transport = static::getContainer()->get('messenger.transport.'.$transportName);
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $envelopes = array_values([...$transport->getSent()]);
        $transport->reset();

        return $envelopes;
    }

    /**
     * Handles the scans the way the worker would, and what they queue in turn (moving content in or out of
     * quarantine).
     *
     * @param list<Envelope> $envelopes
     */
    private function runScans(array $envelopes): void
    {
        $bus = static::getContainer()->get(MessageBusInterface::class);
        $events = static::getContainer()->get(EventDispatcherInterface::class);
        while ([] !== $envelopes) {
            foreach ($envelopes as $envelope) {
                $handled = $bus->dispatch($envelope->with(new ReceivedStamp('scan')));
                $events->dispatch(new WorkerMessageHandledEvent($handled, 'scan'));
            }
            $envelopes = $this->takeQueued();
        }
        $this->entityManager()->clear();
    }

    /**
     * Fails the scan the way the worker does on its last attempt, after the transport's five retries.
     */
    private function giveUp(Envelope $envelope): void
    {
        $envelope = $envelope->with(new RedeliveryStamp(5));
        try {
            $this->runScans([$envelope]);
            self::fail('The scan was expected to fail.');
        } catch (HandlerFailedException $exception) {
            $event = new WorkerMessageFailedEvent($envelope, 'scan', $exception);
            static::getContainer()->get(EventDispatcherInterface::class)->dispatch($event);
            self::assertFalse($event->willRetry());
        }
        $this->entityManager()->clear();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function rescan(array $input): void
    {
        $tester = $this->commandTester();
        $tester->execute($input);
        $tester->assertCommandIsSuccessful();
        $this->runScans($this->takeQueued(RescanAttachmentsCommand::TRANSPORT));
    }

    private function commandTester(): CommandTester
    {
        return new CommandTester(new Application($this->client->getKernel())->find('app:attachments:rescan'));
    }

    private function attachment(string $filename): Attachment
    {
        $attachment = static::getContainer()->get(AttachmentRepository::class)->findOneBy(['filename' => $filename]);
        self::assertInstanceOf(Attachment::class, $attachment);

        return $attachment;
    }

    private function signedUrl(Attachment $attachment): string
    {
        return static::getContainer()->get(UriSigner::class)->sign('http://localhost/content/attachments/'.$attachment->getId()->toRfc4122());
    }

    /**
     * @return array<string, mixed>
     */
    private function listed(string $filename, string $uid = '1'): array
    {
        $messageId = $this->syncedMessage($uid)['id'];
        self::assertIsString($messageId);
        $this->client->request('GET', '/api/v1/messages/'.$messageId.'/body', server: $this->kim);
        self::assertResponseIsSuccessful();
        $attachments = $this->decodedResponse()['attachments'] ?? null;
        self::assertIsArray($attachments);
        foreach ($attachments as $attachment) {
            self::assertIsArray($attachment);
            if (($attachment['filename'] ?? null) === $filename) {
                /** @var array<string, mixed> */
                return $attachment;
            }
        }
        self::fail('No attachment named '.$filename);
    }

    /**
     * @return array<string, mixed>
     */
    private function syncedMessage(string $uid = '1'): array
    {
        $this->client->request('GET', '/api/v1/sync/changes', server: $this->kim);
        $messages = $this->decodedResponse()['messages'] ?? null;
        self::assertIsArray($messages);
        $id = $this->entityManager()->getConnection()->fetchOne('SELECT message_id FROM message_location WHERE remote_uid = :uid', ['uid' => $uid]);
        foreach ($messages as $message) {
            self::assertIsArray($message);
            if (($message['id'] ?? null) === $id) {
                /** @var array<string, mixed> */
                return $message;
            }
        }
        self::fail('No synced message with UID '.$uid);
    }

    private function newDraft(): string
    {
        $this->client->jsonRequest('POST', '/api/v1/drafts', ['mailAccountId' => $this->accountId, 'kind' => 'new', 'to' => [['address' => 'anna@finance.example', 'name' => null]], 'cc' => [], 'bcc' => [], 'subject' => 'Notes', 'html' => '', 'quoteHtml' => ''], $this->kim);
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('id');
    }

    /**
     * Uploads a file to the draft; the scans it queued are kept in $uploadScans.
     *
     * @return array<string, mixed>
     */
    private function uploadFile(string $draftId, string $filename, string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        self::assertIsString($path);
        file_put_contents($path, $content);
        $this->client->request('POST', '/api/v1/drafts/'.$draftId.'/attachments', files: ['file' => new UploadedFile($path, $filename, null, null, true)], server: $this->kim + ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(201);
        $this->uploadScans = $this->takeQueued();

        /** @var array<string, mixed> */
        return $this->decodedResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private function outgoing(string $draftId): array
    {
        return ['mailAccountId' => $this->accountId, 'to' => [['address' => 'anna@finance.example']], 'subject' => 'Notes', 'html' => '<p>See attached.</p>', 'draftId' => $draftId];
    }

    private function signedDraftUrl(mixed $id): string
    {
        self::assertIsString($id);

        return static::getContainer()->get(UriSigner::class)->sign('http://localhost/content/draft-attachments/'.$id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function changedMessages(int $since): array
    {
        $this->client->request('GET', '/api/v1/sync/changes?since='.$since, server: $this->kim);
        $messages = $this->decodedResponse()['messages'] ?? null;
        self::assertIsArray($messages);

        /** @var list<array<string, mixed>> */
        return $messages;
    }

    private function cursor(): int
    {
        $this->client->request('GET', '/api/v1/sync/changes', server: $this->kim);
        $cursor = $this->decodedResponse()['cursor'] ?? null;
        self::assertIsInt($cursor);

        return $cursor;
    }

    private function scanner(): FakeVirusScanner
    {
        return static::getContainer()->get(FakeVirusScanner::class);
    }

    private function plaintext(string $key): string
    {
        return static::getContainer()->get(BlobVault::class)->read($this->accountOwner($this->accountId), $key);
    }

    /** Where the verdict cache keeps the verdict for $content. */
    private function cacheKeyOf(string $content): string
    {
        return hash_hmac('sha256', hash('sha256', $content, true), static::getContainer()->get(KeyRing::class)->scanCacheKey()['key']);
    }

    private function storage(): InMemoryObjectStorage
    {
        return static::getContainer()->get(InMemoryObjectStorage::class);
    }

    private function hub(): RecordingMercureHub
    {
        return static::getContainer()->get(RecordingMercureHub::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
