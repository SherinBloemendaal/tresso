<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Mail\Autoconfig\ServerSettingsDetector;
use App\Tests\Support\MockHttpRouter;
use App\Tests\Support\StaticMxResolver;
use App\Tests\Unit\Mail\AutoconfigParserTest;

final class MailServerSettingsTest extends DatabaseTestCase
{
    use AuthenticatesUsers;

    /** @var array<string, string> */
    private array $kim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapOAuthClients();
        $this->createUser('kim@example.test', 'Kim');
        $this->kim = $this->userHeaders('kim@example.test', ['settings:read', 'settings:write']);
        static::getContainer()->get('cache.mail_autoconfig')->clear();
    }

    public function testFindsSettingsInTheIspdb(): void
    {
        $this->router()->respond(ServerSettingsDetector::ISPDB_URL.'fastmail.com', AutoconfigParserTest::FASTMAIL);

        $settings = $this->detect('Kim@FastMail.com');

        self::assertSame([
            'emailAddress' => 'kim@fastmail.com',
            'found' => true,
            'source' => 'ispdb',
            'username' => 'kim@fastmail.com',
            'imap' => ['host' => 'imap.fastmail.com', 'port' => 993, 'security' => 'tls'],
            'smtp' => ['host' => 'smtp.fastmail.com', 'port' => 587, 'security' => 'starttls'],
            'authentication' => ['password', 'oauth2'],
        ], $settings);
    }

    public function testPrefersTheProvidersOwnAutoconfig(): void
    {
        $own = str_replace(['imap.fastmail.com', 'IMAP.fastmail.com'], 'mail.company.example', AutoconfigParserTest::FASTMAIL);
        $this->router()->respond('https://autoconfig.company.example/mail/config-v1.1.xml', $own);
        $this->router()->respond(ServerSettingsDetector::ISPDB_URL.'company.example', AutoconfigParserTest::FASTMAIL);

        $settings = $this->detect('kim@company.example');

        self::assertSame('provider', $settings['source']);
        self::assertSame(['host' => 'mail.company.example', 'port' => 993, 'security' => 'tls'], $settings['imap']);
        self::assertContains('https://autoconfig.company.example/mail/config-v1.1.xml?emailaddress=kim@company.example', $this->router()->requested());
    }

    public function testFindsHostedMailThroughTheMailExchanger(): void
    {
        static::getContainer()->get(StaticMxResolver::class)->set('company.example', ['aspmx.l.fastmail.com']);
        $this->router()->respond(ServerSettingsDetector::ISPDB_URL.'fastmail.com', AutoconfigParserTest::FASTMAIL);

        $settings = $this->detect('kim@company.example');

        self::assertSame('ispdb-mx', $settings['source']);
        self::assertSame('kim@company.example', $settings['username']);
    }

    public function testReportsWhenNothingIsKnown(): void
    {
        $settings = $this->detect('kim@unknown.example');

        self::assertFalse($settings['found']);
        self::assertNull($settings['imap']);
        self::assertSame([], $settings['authentication']);
    }

    public function testRemembersTheAnswerPerDomain(): void
    {
        $this->router()->respond(ServerSettingsDetector::ISPDB_URL.'fastmail.com', AutoconfigParserTest::FASTMAIL);
        $this->detect('kim@fastmail.com');
        $before = \count($this->router()->requested());

        $settings = $this->detect('lisa@fastmail.com');

        self::assertSame($before, \count($this->router()->requested()));
        self::assertSame('lisa@fastmail.com', $settings['username']);
    }

    public function testRejectsInvalidAddresses(): void
    {
        $this->client->request('GET', '/api/v1/mail-server-settings?emailAddress=not-an-address', server: $this->kim);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('GET', '/api/v1/mail-server-settings', server: $this->kim);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRequiresSettingsWriteScope(): void
    {
        $this->client->request('GET', '/api/v1/mail-server-settings?emailAddress=kim@fastmail.com', server: $this->userHeaders('kim@example.test', ['settings:read']));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return array<string, mixed>
     */
    private function detect(string $emailAddress): array
    {
        $this->client->request('GET', '/api/v1/mail-server-settings?'.http_build_query(['emailAddress' => $emailAddress]), server: $this->kim);
        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> */
        return $this->decodedResponse();
    }

    private function router(): MockHttpRouter
    {
        return static::getContainer()->get(MockHttpRouter::class);
    }
}
