<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Attachment;
use App\Enum\ScanStatus;
use App\Repository\AttachmentRepository;
use App\Tests\Support\InMemoryObjectStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\UriSigner;

final class MessageBodyTest extends DatabaseTestCase
{
    use AuthenticatesUsers;

    private const string LOGO = "\x89PNG\r\n\x1a\nlogo-bytes";
    private const string INVOICE = "%PDF-1.7\ninvoice-bytes";

    /** @var array<string, string> */
    private array $kim;

    private string $messageId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapOAuthClients();
        $this->createUser('kim@example.test', 'Kim');
        $this->createUser('lisa@example.test', 'Lisa');
        $this->kim = $this->userHeaders('kim@example.test', ['mail:read', 'settings:write']);

        $this->client->jsonRequest('POST', '/api/v1/mail-accounts', [
            'emailAddress' => 'kim@company.example',
            'displayName' => 'Work',
            'username' => 'kim@company.example',
            'password' => 'app-password-1',
            'imap' => ['host' => 'imap.company.example', 'port' => 993, 'security' => 'tls'],
            'smtp' => ['host' => 'smtp.company.example', 'port' => 465, 'security' => 'tls'],
        ], $this->kim);
        $accountId = $this->responseString('id');

        $raw = $this->rawMessage();
        $rawSha = hash('sha256', $raw);
        $rawKey = 'raw/'.substr($rawSha, 0, 2).'/'.$rawSha;
        $this->storage()->put($rawKey, $raw);
        foreach ([self::LOGO, self::INVOICE] as $content) {
            $sha = hash('sha256', $content);
            $this->storage()->put('attachments/'.substr($sha, 0, 2).'/'.$sha, $content);
        }

        $worker = $this->workerHeaders('sync:lease sync:ingest');
        $this->client->jsonRequest('POST', '/internal/sync/leases', ['workerId' => 'w1'], $worker);
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$accountId.'/folders', ['workerId' => 'w1', 'folders' => [
            ['remoteName' => 'INBOX', 'displayName' => 'Inbox', 'role' => 'inbox', 'uidValidity' => 1],
        ]], $worker);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('POST', '/internal/sync/accounts/'.$accountId.'/messages', ['workerId' => 'w1', 'folder' => 'INBOX', 'messages' => [[
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
                ['filename' => 'logo.png', 'contentType' => 'image/png', 'size' => \strlen(self::LOGO), 'sha256' => hash('sha256', self::LOGO), 'contentId' => 'logo@finance', 'inline' => true],
                ['filename' => 'Factuur 42 – oktober.pdf', 'contentType' => 'application/pdf', 'size' => \strlen(self::INVOICE), 'sha256' => hash('sha256', self::INVOICE)],
            ],
        ]]], $worker);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/v1/sync/changes', server: $this->kim);
        $messages = $this->decodedResponse()['messages'] ?? null;
        self::assertIsArray($messages);
        self::assertIsArray($messages[0] ?? null);
        self::assertIsString($messages[0]['id'] ?? null);
        $this->messageId = $messages[0]['id'];
    }

    public function testReturnsSanitizedHtmlWithRemoteContentBlocked(): void
    {
        $body = $this->body();

        $html = $body['html'];
        self::assertIsString($html);
        self::assertStringContainsString('<p>Your invoice for <b>October</b>.</p>', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('onerror', $html);
        self::assertStringNotContainsString('tracker.example', $html);
        self::assertTrue($body['hasRemoteContent']);
        self::assertFalse($body['remoteContentLoaded']);
        self::assertSame('Your invoice for October.', $body['text']);
    }

    public function testLoadsRemoteContentOnRequest(): void
    {
        $body = $this->body('?remoteContent=allow');

        self::assertIsString($body['html']);
        self::assertStringContainsString('src="https://tracker.example/open.gif"', $body['html']);
        self::assertTrue($body['remoteContentLoaded']);
    }

    public function testInlineImagesPointAtTheirSignedContentUrl(): void
    {
        $body = $this->body();
        $logo = $this->attachment($body, 'logo.png');

        self::assertIsString($logo['url']);
        self::assertStringStartsWith('http://localhost/content/attachments/', $logo['url']);
        self::assertIsString($body['html']);
        $image = \Dom\HTMLDocument::createFromString($body['html'], \LIBXML_NOERROR)->querySelector('img[alt="Finance"]');
        self::assertNotNull($image);
        self::assertSame($logo['url'], $image->getAttribute('src'));
    }

    public function testListsAttachments(): void
    {
        $invoice = $this->attachment($this->body(), 'Factuur 42 – oktober.pdf');

        self::assertSame('application/pdf', $invoice['contentType']);
        self::assertSame(\strlen(self::INVOICE), $invoice['size']);
        self::assertFalse($invoice['inline']);
        self::assertNull($invoice['contentId']);
        self::assertSame('pending', $invoice['scanStatus']);
    }

    public function testServesInlineImagesThroughTheSignedUrl(): void
    {
        $url = $this->attachment($this->body(), 'logo.png')['url'];
        self::assertIsString($url);

        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSame(self::LOGO, $this->streamedContent());
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertResponseHeaderSame('Content-Disposition', 'inline; filename=logo.png');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('Content-Security-Policy', "default-src 'none'; sandbox");
    }

    public function testOtherFilesAlwaysDownload(): void
    {
        $url = $this->attachment($this->body(), 'Factuur 42 – oktober.pdf')['url'];
        self::assertIsString($url);

        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSame(self::INVOICE, $this->streamedContent());
        self::assertResponseHeaderSame('Content-Type', 'application/octet-stream');
        self::assertResponseHeaderSame('Content-Disposition', "attachment; filename=\"Factuur 42 _ oktober.pdf\"; filename*=utf-8''Factuur%2042%20%E2%80%93%20oktober.pdf");
    }

    public function testRejectsTamperedOrUnsignedUrls(): void
    {
        $url = $this->attachment($this->body(), 'logo.png')['url'];
        self::assertIsString($url);
        $invoiceId = $this->attachment($this->body(), 'Factuur 42 – oktober.pdf')['id'];
        self::assertIsString($invoiceId);
        $logoId = $this->attachment($this->body(), 'logo.png')['id'];
        self::assertIsString($logoId);

        $this->client->request('GET', str_replace($logoId, $invoiceId, $url));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/content/attachments/'.$logoId);
        self::assertResponseStatusCodeSame(403);
    }

    public function testRejectsExpiredUrls(): void
    {
        $logoId = $this->attachment($this->body(), 'logo.png')['id'];
        self::assertIsString($logoId);
        $expired = static::getContainer()->get(UriSigner::class)->sign('http://localhost/content/attachments/'.$logoId, new \DateTimeImmutable('-1 minute'));

        $this->client->request('GET', $expired);

        self::assertResponseStatusCodeSame(403);
    }

    public function testQuarantinedAttachmentsAreNeverServed(): void
    {
        $url = $this->attachment($this->body(), 'Factuur 42 – oktober.pdf')['url'];
        self::assertIsString($url);
        $this->markInfected('Factuur 42 – oktober.pdf');

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);

        $invoice = $this->attachment($this->body(), 'Factuur 42 – oktober.pdf');
        self::assertNull($invoice['url']);
        self::assertSame('infected', $invoice['scanStatus']);
    }

    public function testOtherUsersMessagesDoNotExist(): void
    {
        $this->client->request('GET', '/api/v1/messages/'.$this->messageId.'/body', server: $this->userHeaders('lisa@example.test', ['mail:read']));

        self::assertResponseStatusCodeSame(404);
    }

    public function testRequiresMailReadScope(): void
    {
        $this->client->request('GET', '/api/v1/messages/'.$this->messageId.'/body', server: $this->userHeaders('kim@example.test', ['settings:read']));

        self::assertResponseStatusCodeSame(403);
    }

    public function testRejectsUnknownRemoteContentMode(): void
    {
        $this->client->request('GET', '/api/v1/messages/'.$this->messageId.'/body?remoteContent=sometimes', server: $this->kim);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $query = ''): array
    {
        $this->client->request('GET', '/api/v1/messages/'.$this->messageId.'/body'.$query, server: $this->kim);
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> */
        return $this->decodedResponse();
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function attachment(array $body, string $filename): array
    {
        self::assertIsArray($body['attachments']);
        foreach ($body['attachments'] as $attachment) {
            self::assertIsArray($attachment);
            if (($attachment['filename'] ?? null) === $filename) {
                /** @var array<string, mixed> */
                return $attachment;
            }
        }
        self::fail('No attachment named '.$filename);
    }

    private function markInfected(string $filename): void
    {
        $attachment = static::getContainer()->get(AttachmentRepository::class)->findOneBy(['filename' => $filename]);
        self::assertInstanceOf(Attachment::class, $attachment);
        $attachment->recordScan(ScanStatus::Infected, 'Eicar-Test-Signature');
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function streamedContent(): string
    {
        return (string) $this->client->getInternalResponse()->getContent();
    }

    private function storage(): InMemoryObjectStorage
    {
        return static::getContainer()->get(InMemoryObjectStorage::class);
    }

    private function rawMessage(): string
    {
        return str_replace("\n", "\r\n", <<<'EML'
            From: Finance <billing@finance.example>
            To: kim@company.example
            Subject: Invoice 42
            Message-ID: <invoice-42@finance.example>
            MIME-Version: 1.0
            Content-Type: multipart/mixed; boundary="mixed"

            --mixed
            Content-Type: multipart/alternative; boundary="alt"

            --alt
            Content-Type: text/plain; charset=utf-8

            Your invoice for October.
            --alt
            Content-Type: multipart/related; boundary="rel"

            --rel
            Content-Type: text/html; charset=utf-8

            <html><body><img src="cid:logo@finance" alt="Finance"><p>Your invoice for <b>October</b>.</p><img src="https://tracker.example/open.gif"><img src="x" onerror="alert(1)"><script>alert(1)</script></body></html>
            --rel
            Content-Type: image/png
            Content-ID: <logo@finance>
            Content-Disposition: inline; filename="logo.png"
            Content-Transfer-Encoding: base64

            iVBORw0KGgpsb2dvLWJ5dGVz
            --rel--
            --alt--
            --mixed
            Content-Type: application/pdf; name="invoice.pdf"
            Content-Disposition: attachment; filename="invoice.pdf"
            Content-Transfer-Encoding: base64

            JVBERi0xLjcKaW52b2ljZS1ieXRlcw==
            --mixed--
            EML);
    }
}
