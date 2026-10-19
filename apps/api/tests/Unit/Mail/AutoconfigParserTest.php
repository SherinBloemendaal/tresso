<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Enum\ConnectionSecurity;
use App\Mail\Autoconfig\AutoconfigParser;
use PHPUnit\Framework\TestCase;

final class AutoconfigParserTest extends TestCase
{
    public const string FASTMAIL = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <clientConfig version="1.1">
          <emailProvider id="fastmail.com">
            <domain>fastmail.com</domain>
            <incomingServer type="pop3">
              <hostname>pop.fastmail.com</hostname><port>995</port><socketType>SSL</socketType>
              <username>%EMAILADDRESS%</username><authentication>password-cleartext</authentication>
            </incomingServer>
            <incomingServer type="imap">
              <hostname>imap.fastmail.com</hostname><port>143</port><socketType>plain</socketType>
              <username>%EMAILADDRESS%</username><authentication>password-cleartext</authentication>
            </incomingServer>
            <incomingServer type="imap">
              <hostname>imap.fastmail.com</hostname><port>143</port><socketType>STARTTLS</socketType>
              <username>%EMAILADDRESS%</username><authentication>password-cleartext</authentication>
            </incomingServer>
            <incomingServer type="imap">
              <hostname>IMAP.fastmail.com</hostname><port>993</port><socketType>SSL</socketType>
              <username>%EMAILADDRESS%</username><authentication>password-cleartext</authentication><authentication>OAuth2</authentication>
            </incomingServer>
            <outgoingServer type="smtp">
              <hostname>smtp.fastmail.com</hostname><port>587</port><socketType>STARTTLS</socketType>
              <username>%EMAILADDRESS%</username><authentication>password-cleartext</authentication>
            </outgoingServer>
          </emailProvider>
        </clientConfig>
        XML;

    public function testPicksTheEncryptedImapAndSmtpServers(): void
    {
        $servers = new AutoconfigParser()->parse(self::FASTMAIL, 'kim@fastmail.com');

        self::assertNotNull($servers);
        self::assertSame('imap.fastmail.com', $servers->imap->host);
        self::assertSame(993, $servers->imap->port);
        self::assertSame(ConnectionSecurity::Tls, $servers->imap->security);
        self::assertSame('smtp.fastmail.com', $servers->smtp->host);
        self::assertSame(ConnectionSecurity::StartTls, $servers->smtp->security);
        self::assertSame('kim@fastmail.com', $servers->username);
        self::assertSame(['password', 'oauth2'], $servers->authentication);
    }

    public function testFillsInTheLocalPart(): void
    {
        $xml = str_replace('%EMAILADDRESS%', '%EMAILLOCALPART%', self::FASTMAIL);

        self::assertSame('kim', new AutoconfigParser()->parse($xml, 'kim@fastmail.com')?->username);
    }

    public function testNeverSuggestsUnencryptedServers(): void
    {
        $xml = (string) preg_replace('~<socketType>(SSL|STARTTLS)</socketType>~', '<socketType>plain</socketType>', self::FASTMAIL);

        self::assertNull(new AutoconfigParser()->parse($xml, 'kim@fastmail.com'));
    }

    public function testRejectsGarbage(): void
    {
        $parser = new AutoconfigParser();

        self::assertNull($parser->parse('<html><body>Not found</body></html>', 'kim@x.example'));
        self::assertNull($parser->parse('not xml at all', 'kim@x.example'));
        self::assertNull($parser->parse('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><clientConfig><emailProvider><incomingServer type="imap"><hostname>&e;</hostname><port>993</port><socketType>SSL</socketType></incomingServer></emailProvider></clientConfig>', 'kim@x.example'));
    }

    public function testRejectsInvalidHostnames(): void
    {
        $xml = str_replace('IMAP.fastmail.com', 'imap.fastmail.com/evil', self::FASTMAIL);
        $servers = new AutoconfigParser()->parse($xml, 'kim@fastmail.com');

        self::assertSame(ConnectionSecurity::StartTls, $servers?->imap->security, 'The invalid server is skipped, the next encrypted one is used.');
    }
}
