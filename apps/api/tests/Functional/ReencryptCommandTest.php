<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\MailAccount;
use App\Mail\MessageFields;
use App\Security\Crypto\Aead;
use App\Security\Crypto\CredentialVault;
use App\Security\Crypto\UserKeys;
use App\Storage\BlobKey;
use App\Storage\BlobKind;
use App\Storage\BlobVault;
use App\Storage\ReencryptUserData;
use App\Storage\ReencryptUserDataHandler;
use App\Storage\Reencryption;
use App\Storage\SweepReencryption;
use App\Tests\Support\InMemoryObjectStorage;
use App\Tests\Support\StatementHook;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Data stored before encryption is plaintext under its plain SHA-256, possibly shared between users; app:crypto:reencrypt
 * moves it, and data left on an older key version after a rotation, to the user's current key.
 */
final class ReencryptCommandTest extends DatabaseTestCase
{
    use AuthenticatesUsers;
    use StoresMail;

    private const string RAW = "From: billing@finance.example\r\nSubject: Invoice 42\r\n\r\nYour invoice for October.\r\n";
    private const string INVOICE = "%PDF-1.7\ninvoice-bytes";
    private const string MALWARE = "infected-bytes";
    private const string NOTES = 'Notes for the meeting';
    private const string OUTGOING = "Subject: Plan\r\n\r\nThe plan.\r\n";

    private string $accountId;
    private Uuid $owner;
    private string $messageId;
    private string $draftFileId;
    private string $outgoingId;

    protected function tearDown(): void
    {
        StatementHook::reset();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapOAuthClients();
        $this->createUser('kim@example.test', 'Kim');
        $kim = $this->userHeaders('kim@example.test', ['settings:write', 'mail:write', 'mail:send']);
        $this->client->jsonRequest('POST', '/api/v1/mail-accounts', [
            'emailAddress' => 'kim@company.example',
            'displayName' => 'Work',
            'username' => 'kim@company.example',
            'password' => 'app-password-1',
            'imap' => ['host' => 'imap.company.example', 'port' => 993, 'security' => 'tls'],
            'smtp' => ['host' => 'smtp.company.example', 'port' => 465, 'security' => 'tls'],
        ], $kim);
        $this->accountId = $this->responseString('id');
        $this->owner = $this->accountOwner($this->accountId);

        $worker = $this->workerHeaders('sync:lease sync:ingest');
        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => 'w1'], $worker);
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/folders', ['workerId' => 'w1', 'folders' => [
            ['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1],
        ]], $worker);
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$this->accountId.'/messages', ['workerId' => 'w1', 'folder' => 'INBOX', 'messages' => [[
            'remoteUid' => '1',
            'rawObjectKey' => $this->storeAsWorker($this->accountId, BlobKind::Raw, self::RAW),
            'keyVersion' => 1,
            'size' => \strlen(self::RAW),
            'receivedAt' => '2026-10-19T08:00:00+00:00',
            'messageId' => '<invoice-42@finance.example>',
            'subject' => 'Invoice 42',
            'from' => ['address' => 'billing@finance.example', 'name' => 'Finance'],
            'to' => [['address' => 'kim@company.example']],
            'snippet' => 'Your invoice',
            'bodyText' => 'Your invoice for October.',
            'attachments' => [
                ['filename' => 'invoice.pdf', 'contentType' => 'application/pdf', 'size' => \strlen(self::INVOICE), 'fingerprint' => $this->fingerprintFor($this->accountId, self::INVOICE)],
                ['filename' => 'invoice.zip', 'contentType' => 'application/zip', 'size' => \strlen(self::MALWARE), 'fingerprint' => $this->fingerprintFor($this->accountId, self::MALWARE)],
            ],
        ]]], $worker);
        self::assertResponseIsSuccessful();
        $messageId = $this->connection()->fetchOne('SELECT id FROM message');
        self::assertIsString($messageId);
        $this->messageId = $messageId;

        $this->client->jsonRequest('POST', '/api/v1/drafts', ['mailAccountId' => $this->accountId, 'kind' => 'new', 'to' => [], 'cc' => [], 'bcc' => [], 'subject' => 'Notes', 'html' => '', 'quoteHtml' => ''], $kim);
        self::assertResponseStatusCodeSame(201);
        $draftId = $this->responseString('id');
        $this->draftFileId = Uuid::v7()->toRfc4122();
        $this->connection()->executeStatement(
            "INSERT INTO draft_attachment (id, draft_id, filename, content_type, size, sha256, object_key, key_version, scan_status, inline, position, created_at, scan_requested_at)
             VALUES (:id, :draft, 'notes.txt', 'text/plain', :size, :sha, :key, NULL, 'clean', false, 0, NOW(), NOW())",
            ['id' => $this->draftFileId, 'draft' => $draftId, 'size' => \strlen(self::NOTES), 'sha' => hash('sha256', self::NOTES), 'key' => BlobKey::legacy(BlobKind::Uploads, hash('sha256', self::NOTES))],
        );

        $this->client->jsonRequest('POST', '/api/v1/outgoing-messages', ['mailAccountId' => $this->accountId, 'to' => [['address' => 'anna@finance.example']], 'subject' => 'Plan', 'html' => '<p>The plan.</p>', 'sendAt' => new \DateTimeImmutable('+1 month')->format(\DATE_ATOM)], $kim);
        self::assertResponseStatusCodeSame(201);
        $this->outgoingId = $this->responseString('id');

        $this->storeAsBeforeEncryption();
    }

    public function testMovesEverythingStoredBeforeEncryptionToTheUsersKey(): void
    {
        $tester = $this->reencrypt(['--user' => ['kim@example.test']]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 of 1 users are completely on their current key', $tester->getDisplay());

        $message = $this->connection()->fetchAssociative('SELECT raw_object_key, raw_key_version, snippet, sealed_snippet FROM message WHERE id = :id', ['id' => $this->messageId]);
        self::assertIsArray($message);
        self::assertSame($this->rawKeyFor($this->accountId, self::RAW), $message['raw_object_key']);
        self::assertSame(1, $message['raw_key_version']);
        self::assertSame(self::RAW, $this->plaintext(self::string($message['raw_object_key'])));
        self::assertSame('', $message['snippet']);
        self::assertSame('Your invoice', $this->fields()->snippetOf($this->owner, Uuid::fromString($this->messageId), self::nullable($message['sealed_snippet']), ''));

        $text = $this->connection()->fetchAssociative('SELECT text, sealed_text FROM message_text WHERE message_id = :id', ['id' => $this->messageId]);
        self::assertIsArray($text);
        self::assertNull($text['text']);
        self::assertSame('Your invoice for October.', $this->fields()->textOf($this->owner, Uuid::fromString($this->messageId), self::nullable($text['sealed_text']), null));

        $outgoing = $this->connection()->fetchAssociative('SELECT raw_object_key, raw_key_version FROM outgoing_message WHERE id = :id', ['id' => $this->outgoingId]);
        self::assertIsArray($outgoing);
        self::assertSame(1, $outgoing['raw_key_version']);
        self::assertSame(self::OUTGOING, $this->plaintext(self::string($outgoing['raw_object_key'])));

        $file = $this->connection()->fetchAssociative('SELECT object_key, sha256, key_version FROM draft_attachment WHERE id = :id', ['id' => $this->draftFileId]);
        self::assertIsArray($file);
        self::assertSame(BlobKey::of(BlobKind::Uploads, $this->owner, $this->fingerprintFor($this->accountId, self::NOTES)), $file['object_key']);
        self::assertSame(self::NOTES, $this->plaintext(self::string($file['object_key'])));

        $credentials = static::getContainer()->get(EntityManagerInterface::class)->find(MailAccount::class, $this->accountId);
        self::assertInstanceOf(MailAccount::class, $credentials);
        self::assertStringStartsWith('f1.1.', (string) $credentials->getEncryptedCredentials());
        self::assertSame('app-password-1', static::getContainer()->get(CredentialVault::class)->open($credentials)['password'] ?? null);
    }

    public function testKeepsQuarantinedContentInQuarantine(): void
    {
        $this->reencrypt(['--all' => true])->assertCommandIsSuccessful();

        $rows = $this->connection()->fetchAllKeyValue('SELECT filename, sha256 FROM attachment WHERE key_version = 1');
        self::assertSame($this->fingerprintFor($this->accountId, self::INVOICE), $rows['invoice.pdf'] ?? null);
        self::assertSame($this->fingerprintFor($this->accountId, self::MALWARE), $rows['invoice.zip'] ?? null);
        self::assertSame(self::INVOICE, $this->plaintext(BlobKey::of(BlobKind::Attachments, $this->owner, self::string($rows['invoice.pdf']))));
        self::assertSame(self::MALWARE, $this->plaintext(BlobKey::of(BlobKind::Quarantine, $this->owner, self::string($rows['invoice.zip']))));
        self::assertFalse($this->storage()->has(BlobKey::of(BlobKind::Attachments, $this->owner, self::string($rows['invoice.zip']))), 'Not released by re-encrypting it.');
        self::assertSame('infected', $this->connection()->fetchOne("SELECT scan_status FROM attachment WHERE filename = 'invoice.zip'"));
    }

    public function testRunningAgainChangesNothing(): void
    {
        $this->reencrypt(['--all' => true])->assertCommandIsSuccessful();
        $objects = $this->storage()->keys();
        $rows = $this->connection()->fetchAllAssociative('SELECT id, raw_object_key, sealed_snippet FROM message');

        $tester = $this->reencrypt(['--all' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString('re-encrypted', $tester->getDisplay());
        self::assertSame($objects, $this->storage()->keys());
        self::assertSame($rows, $this->connection()->fetchAllAssociative('SELECT id, raw_object_key, sealed_snippet FROM message'));
    }

    public function testStatusCountsWhatIsLeftWithoutChangingIt(): void
    {
        $tester = $this->reencrypt(['--all' => true, '--status' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/messages\s+1\b/', $tester->getDisplay());
        self::assertMatchesRegularExpression('/attachments\s+2\b/', $tester->getDisplay());
        self::assertStringContainsString('still on content from before encryption: 5', $tester->getDisplay());
        self::assertNull($this->connection()->fetchOne('SELECT raw_key_version FROM message'));
    }

    public function testARowWhoseContentIsGoneIsLeftForTheNextRun(): void
    {
        $this->storage()->delete(BlobKey::legacy(BlobKind::Raw, hash('sha256', self::RAW)));

        $tester = $this->reencrypt(['--all' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('1 rows could not be re-encrypted', $tester->getDisplay());
        self::assertNull($this->connection()->fetchOne('SELECT raw_key_version FROM message'));
        self::assertSame(1, $this->connection()->fetchOne('SELECT raw_key_version FROM outgoing_message'), 'The rest went on.');
    }

    public function testPurgesThePlaintextOnlyOnceNothingRefersToIt(): void
    {
        $tester = $this->reencrypt(['--purge-legacy' => true]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertTrue($this->storage()->has(BlobKey::legacy(BlobKind::Raw, hash('sha256', self::RAW))));

        $this->reencrypt(['--all' => true])->assertCommandIsSuccessful();
        $tester = $this->reencrypt(['--purge-legacy' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Deleted 5 plaintext objects', $tester->getDisplay());
        foreach ($this->storage()->keys() as $key) {
            self::assertFalse(BlobKey::isLegacy($key), $key.' is plaintext from before.');
        }
    }

    public function testMovesDataToANewKeyAndRetiresTheOldOneOnceItIsUnused(): void
    {
        $this->reencrypt(['--all' => true])->assertCommandIsSuccessful();
        $keys = static::getContainer()->get(UserKeys::class);
        $keys->rotate($this->owner, new \DateTimeImmutable());
        $keys->reset();

        $tester = $this->reencrypt(['--all' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('their older keys go once the', $tester->getDisplay());
        self::assertSame(2, $this->connection()->fetchOne('SELECT raw_key_version FROM message'));
        self::assertSame([2], $this->connection()->fetchFirstColumn('SELECT DISTINCT key_version FROM attachment'));
        self::assertStringStartsWith('f1.2.', self::string($this->connection()->fetchOne('SELECT sealed_text FROM message_text')));
        self::assertSame([1, 2], $this->connection()->fetchFirstColumn('SELECT version FROM user_key ORDER BY version'));

        $this->connection()->executeStatement("UPDATE user_key SET created_at = created_at - interval '20 minutes'");
        $this->reencrypt(['--all' => true])->assertCommandIsSuccessful();

        self::assertSame([2], $this->connection()->fetchFirstColumn('SELECT version FROM user_key'));
        $keys->reset();
        self::assertSame(self::RAW, $this->plaintext($this->rawKeyFor($this->accountId, self::RAW)), 'Readable with the new key alone.');
    }

    public function testHoldsTheUploadLockUntilTheDraftFileNamesItsNewObject(): void
    {
        $newKey = BlobKey::of(BlobKind::Uploads, $this->owner, $this->fingerprintFor($this->accountId, self::NOTES));
        $params = $this->connection()->getParams();
        self::assertIsString($params['host'] ?? null);
        self::assertIsString($params['user'] ?? null);
        self::assertIsString($params['password'] ?? null);
        self::assertIsString($params['dbname'] ?? null);
        $other = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => $params['host'], 'port' => \is_int($params['port'] ?? null) ? $params['port'] : 5432, 'user' => $params['user'], 'password' => $params['password'], 'dbname' => $params['dbname']]);
        $collectable = null;
        StatementHook::before('UPDATE draft_attachment SET object_key', static function () use ($other, $newKey, &$collectable): void {
            $collectable = (bool) $other->fetchOne('SELECT pg_try_advisory_lock(hashtextextended(:key, 0))', ['key' => $newKey]);
        });

        $this->reencrypt(['--all' => true])->assertCommandIsSuccessful();

        self::assertFalse($collectable, 'The upload collector waits until the row refers to the new object.');
        $other->close();
    }

    public function testPurgingDeletesWhatReencryptionRecordedWithoutListingTheBucket(): void
    {
        $stray = BlobKey::legacy(BlobKind::Raw, hash('sha256', 'a stray upload no row knew of'));
        $this->storage()->put($stray, 'a stray upload no row knew of');
        $this->reencrypt(['--all' => true])->assertCommandIsSuccessful();
        self::assertSame(8, $this->countRows('SELECT COUNT(*) FROM legacy_object'), 'Every old name the rows had: the raw message, both places of each attachment and of the upload, and the outgoing message.');

        $this->reencrypt(['--purge-legacy' => true])->assertCommandIsSuccessful();

        self::assertTrue($this->storage()->has($stray), 'Only listed with --scan-bucket.');
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM legacy_object'));
        $this->reencrypt(['--purge-legacy' => true, '--scan-bucket' => true])->assertCommandIsSuccessful();
        self::assertFalse($this->storage()->has($stray));
    }

    public function testRunsInTheBackgroundUntilNothingIsLeft(): void
    {
        $this->connection()->executeStatement('UPDATE app_user SET data_key_version = NULL');
        $handler = static::getContainer()->get(ReencryptUserDataHandler::class);

        $handler->onSweep(new SweepReencryption());
        $queued = $this->takeQueued();
        self::assertEquals([new ReencryptUserData($this->owner->toRfc4122())], $queued, 'The sweep finds the user after a deploy.');
        self::assertInstanceOf(ReencryptUserData::class, $queued[0]);
        $handler($queued[0]);

        self::assertSame(0, static::getContainer()->get(Reencryption::class)->legacyContentLeft());
        self::assertSame(1, $this->connection()->fetchOne('SELECT data_key_version FROM app_user WHERE id = :id', ['id' => $this->owner->toRfc4122()]));
        self::assertSame([], $this->takeQueued(), 'Done: it does not queue itself again.');
        $handler->onSweep(new SweepReencryption());
        self::assertSame([], $this->takeQueued(), 'Nor does the sweep.');
    }

    public function testRotatingQueuesTheBackgroundReencryption(): void
    {
        $this->reencrypt(['--all' => true])->assertCommandIsSuccessful();
        $this->takeQueued();

        $tester = new CommandTester(new Application($this->client->getKernel())->find('app:crypto:rotate-user-keys'));
        $tester->execute(['--user' => ['kim@example.test']]);

        $queued = $this->takeQueued();
        self::assertEquals([new ReencryptUserData($this->owner->toRfc4122())], $queued);
        static::getContainer()->get(UserKeys::class)->reset();
        self::assertInstanceOf(ReencryptUserData::class, $queued[0]);
        static::getContainer()->get(ReencryptUserDataHandler::class)($queued[0]);
        self::assertSame(2, $this->connection()->fetchOne('SELECT raw_key_version FROM message'));
        $later = $this->takeQueuedEnvelopes();
        self::assertCount(1, $later, 'It comes back to retire the old key once the new one is old enough.');
        self::assertNotNull($later[0]->last(DelayStamp::class));
    }

    public function testABackgroundRunStopsAtItsDeadlineInsteadOfFinishingABatch(): void
    {
        $reencryption = static::getContainer()->get(Reencryption::class);
        $left = $reencryption->legacyContentLeft();

        $result = $reencryption->run($this->owner, 200, static function (): void {}, new \DateTimeImmutable('-1 second'));

        self::assertSame($left, $reencryption->legacyContentLeft(), 'Nothing moved once its time was up, not even the rest of a batch.');
        self::assertGreaterThan(0, $result->left);
        $reencryption->run($this->owner, 200, static function (): void {});
        self::assertSame(0, $reencryption->legacyContentLeft(), 'The next run goes on.');
    }

    public function testNeedsUsersOrAll(): void
    {
        self::assertSame(Command::INVALID, $this->reencrypt([])->getStatusCode());
    }

    /**
     * Turns what setUp stored into what an installation from before encryption has: plaintext objects under their
     * plain SHA-256, plaintext snippets and body texts, and credentials sealed with the data key itself.
     */
    private function storeAsBeforeEncryption(): void
    {
        $legacy = function (BlobKind $kind, string $content): string {
            $key = BlobKey::legacy($kind, hash('sha256', $content));
            $this->storage()->put($key, $content);

            return $key;
        };
        foreach ($this->storage()->keys() as $key) {
            $this->storage()->delete($key);
        }

        $this->connection()->executeStatement(
            "UPDATE message SET raw_object_key = :key, raw_key_version = NULL, snippet = 'Your invoice', sealed_snippet = NULL",
            ['key' => $legacy(BlobKind::Raw, self::RAW)],
        );
        $this->connection()->executeStatement("UPDATE message_text SET text = 'Your invoice for October.', sealed_text = NULL");
        $legacy(BlobKind::Attachments, self::INVOICE);
        $legacy(BlobKind::Quarantine, self::MALWARE);
        $this->connection()->executeStatement("UPDATE attachment SET key_version = NULL, sha256 = :sha, scan_status = 'clean' WHERE filename = 'invoice.pdf'", ['sha' => hash('sha256', self::INVOICE)]);
        $this->connection()->executeStatement("UPDATE attachment SET key_version = NULL, sha256 = :sha, scan_status = 'infected', scan_signature = 'Eicar' WHERE filename = 'invoice.zip'", ['sha' => hash('sha256', self::MALWARE)]);
        $legacy(BlobKind::Uploads, self::NOTES);
        $this->connection()->executeStatement('UPDATE outgoing_message SET raw_object_key = :key, raw_key_version = NULL', ['key' => $legacy(BlobKind::Outgoing, self::OUTGOING)]);

        $versionOne = static::getContainer()->get(UserKeys::class)->version($this->owner, 1);
        $this->connection()->executeStatement('UPDATE mail_account SET encrypted_credentials = :sealed', [
            'sealed' => 'v1.'.base64_encode(Aead::encrypt('{"type":"password","password":"app-password-1"}', 'tresso:mail-account-credentials:'.$this->accountId, $versionOne)),
        ]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function reencrypt(array $input): CommandTester
    {
        static::getContainer()->get(UserKeys::class)->reset();
        $tester = new CommandTester(new Application($this->client->getKernel())->find('app:crypto:reencrypt'));
        $tester->execute($input);

        return $tester;
    }

    /**
     * @return list<object>
     */
    private function takeQueued(): array
    {
        return array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $this->takeQueuedEnvelopes());
    }

    /**
     * @return list<Envelope>
     */
    private function takeQueuedEnvelopes(): array
    {
        $transport = static::getContainer()->get('messenger.transport.reencrypt');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $envelopes = array_values([...$transport->getSent()]);
        $transport->reset();

        return $envelopes;
    }

    private function plaintext(string $key): string
    {
        return static::getContainer()->get(BlobVault::class)->read($this->owner, $key);
    }

    private function fields(): MessageFields
    {
        return static::getContainer()->get(MessageFields::class);
    }

    private function storage(): InMemoryObjectStorage
    {
        return static::getContainer()->get(InMemoryObjectStorage::class);
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private static function nullable(mixed $value): ?string
    {
        return null === $value ? null : self::string($value);
    }
}
