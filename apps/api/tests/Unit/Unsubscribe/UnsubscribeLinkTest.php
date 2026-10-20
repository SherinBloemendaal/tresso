<?php

declare(strict_types=1);

namespace App\Tests\Unit\Unsubscribe;

use App\Enum\UnsubscribeMethod;
use App\Unsubscribe\UnsubscribeLink;
use PHPUnit\Framework\TestCase;

final class UnsubscribeLinkTest extends TestCase
{
    private const string PASS = 'mx.google.com; dkim=pass header.d=shop.example; spf=pass; dmarc=pass (p=NONE) header.from=shop.example';
    private const string ONE_CLICK = 'List-Unsubscribe=One-Click';

    /**
     * @param array<string, string> $headers
     */
    private static function link(array $headers, string $from = 'news@shop.example', bool $trusted = true): ?UnsubscribeLink
    {
        return UnsubscribeLink::fromMessage(['authentication-results' => self::PASS] + $headers, $from, $trusted);
    }

    public function testAnHttpsAddressWithTheOneClickPromiseIsPostedTo(): void
    {
        $link = self::link(['list-unsubscribe' => '<mailto:leave@shop.example>, <https://esp.example/u?t=abc>', 'list-unsubscribe-post' => self::ONE_CLICK]);

        self::assertNotNull($link);
        self::assertSame(UnsubscribeMethod::OneClick, $link->method);
        self::assertSame('https://esp.example/u?t=abc', $link->uri);
    }

    public function testAForgedSenderIsNeverUnsubscribedFrom(): void
    {
        $headers = ['list-unsubscribe' => '<https://shop.example/u>, <mailto:leave@shop.example>', 'list-unsubscribe-post' => self::ONE_CLICK];

        self::assertNull(UnsubscribeLink::fromMessage($headers, 'news@shop.example', true), 'No authentication results at all.');
        self::assertNull(UnsubscribeLink::fromMessage(['authentication-results' => 'mx.google.com; dmarc=fail (p=NONE) header.from=shop.example'] + $headers, 'news@shop.example', true), 'DMARC failed, even with a policy of none.');
        self::assertNull(UnsubscribeLink::fromMessage(['authentication-results' => 'mx.google.com; spf=pass; dkim=none; dmarc=none header.from=shop.example'] + $headers, 'news@shop.example', true), 'No DMARC record.');
        self::assertNull(self::link($headers, trusted: false), 'A pass in a header the receiving server may not have written proves nothing.');
    }

    public function testAnHttpsAddressWithoutTheOneClickPromiseIsNeverUsed(): void
    {
        self::assertNull(self::link(['list-unsubscribe' => '<https://shop.example/u?t=abc>']));
        self::assertNull(self::link(['list-unsubscribe' => '<https://shop.example/u>', 'list-unsubscribe-post' => 'unsubscribe=yes']));
    }

    public function testOneClickNeedsHttpsOnTheStandardPortWithoutCredentials(): void
    {
        $post = ['list-unsubscribe-post' => 'list-unsubscribe=one-click'];

        self::assertNull(self::link(['list-unsubscribe' => '<http://shop.example/u>'] + $post));
        self::assertNull(self::link(['list-unsubscribe' => '<https://user:secret@shop.example/u>'] + $post));
        self::assertNull(self::link(['list-unsubscribe' => '<https://shop.example:8443/u>'] + $post));
        self::assertNull(self::link(['list-unsubscribe' => '<javascript:alert(1)>'] + $post));
        self::assertSame('https://shop.example:443/u', self::link(['list-unsubscribe' => '<https://shop.example:443/u>'] + $post)?->uri);
        self::assertSame('https://shop.example/u', self::link(['list-unsubscribe' => "<https://shop.example/\r\n u>", 'list-unsubscribe-post' => ' List-Unsubscribe=One-Click '])?->uri);
    }

    public function testWithoutOneClickAnAddressOfTheSendersOwnDomainGetsTheFixedMail(): void
    {
        $link = self::link(['list-unsubscribe' => '<https://shop.example/u>, <mailto:leave+42@lists.shop.example?subject=Wire%20money&body=Send%20it>']);

        self::assertNotNull($link);
        self::assertSame(UnsubscribeMethod::Mailto, $link->method);
        self::assertSame('leave+42@lists.shop.example', $link->recipient, 'A subdomain of the From domain is the sender\'s own.');
        self::assertSame('unsubscribe', UnsubscribeLink::MAIL_TEXT, 'The header\'s subject and body are never used.');
        self::assertSame('leave@shop.example', self::link(['list-unsubscribe' => '<mailto:leave@shop.example>'], 'news@mail.shop.example')?->recipient, 'And so is a parent of it.');
    }

    public function testAMailtoAddressOnAnotherDomainIsNeverWritten(): void
    {
        self::assertNull(self::link(['list-unsubscribe' => '<mailto:boss@company.example>']));
        self::assertNull(self::link(['list-unsubscribe' => '<mailto:leave@notshop.example>']), 'A suffix that is not a parent domain does not count.');
    }

    public function testAMailtoAddressGoesToOneRecipientOnly(): void
    {
        self::assertNull(self::link(['list-unsubscribe' => '<mailto:leave@shop.example,boss@company.example>']));
        self::assertNull(self::link(['list-unsubscribe' => '<mailto:leave@shop.example?cc=boss@company.example>']));
        self::assertNull(self::link(['list-unsubscribe' => '<mailto:leave@shop.example?to=boss@company.example>']));
    }

    public function testNothingUsableMeansNoUnsubscribe(): void
    {
        self::assertNull(self::link([]));
        self::assertNull(self::link(['list-unsubscribe' => 'leave@shop.example']));
        self::assertNull(self::link(['list-unsubscribe' => '<mailto:not-an-address>']));
        self::assertNull(self::link(['list-unsubscribe-post' => self::ONE_CLICK]));
    }
}
