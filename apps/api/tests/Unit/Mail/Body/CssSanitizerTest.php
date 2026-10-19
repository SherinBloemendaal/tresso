<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail\Body;

use App\Mail\Body\CssSanitizer;
use App\Mail\Body\MediaUrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CssSanitizerTest extends TestCase
{
    public function testBlocksRemoteUrlsAndReportsThem(): void
    {
        $policy = new MediaUrlPolicy([], false);

        $css = new CssSanitizer()->sanitize('td { background: url(https://tracker.example/p.gif) no-repeat; }', $policy);

        self::assertSame('td { background: none no-repeat; }', $css);
        self::assertTrue($policy->remoteContentFound());
    }

    public function testKeepsRemoteUrlsWhenAllowed(): void
    {
        $css = new CssSanitizer()->sanitize("td { background: url('https://cdn.example/bg.png'); }", new MediaUrlPolicy([], true));

        self::assertSame('td { background: url("https://cdn.example/bg.png"); }', $css);
    }

    public function testRewritesContentIdUrls(): void
    {
        $css = new CssSanitizer()->sanitize('div { background-image: url("cid:Logo@Finance"); }', new MediaUrlPolicy(['logo@finance' => 'https://api.example/content/a1'], false));

        self::assertSame('div { background-image: url("https://api.example/content/a1"); }', $css);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function evasions(): iterable
    {
        yield 'escaped url function' => ['div { background: u\72l(https://tracker.example/p.gif); }'];
        yield 'escaped letter' => ['div { background: \u\r\l(https://tracker.example/p.gif); }'];
        yield 'comment inside' => ['div { background: url/**/(https://tracker.example/p.gif); }'];
        yield 'upper case' => ['div { background: URL( "https://tracker.example/p.gif" ); }'];
        yield 'import' => ['@import "https://tracker.example/a.css"; div { color: red; }'];
        yield 'import url' => ['@import url(https://tracker.example/a.css); div { color: red; }'];
        yield 'escaped import' => ['@\69mport "https://tracker.example/a.css"; div { color: red; }'];
        yield 'image-set' => ['div { background: image-set("https://tracker.example/p.gif" 1x); }'];
        yield 'font-face' => ['@font-face { font-family: x; src: url(https://tracker.example/f.woff); }'];
    }

    #[DataProvider('evasions')]
    public function testRemoteLoadsCannotBeSmuggledPast(string $input): void
    {
        $css = new CssSanitizer()->sanitize($input, new MediaUrlPolicy([], false));

        self::assertDoesNotMatchRegularExpression('~url\(\s*["\']?https?:~i', $css);
        self::assertDoesNotMatchRegularExpression('~@import|(?<!x-blocked-)image-set\(~i', $css);
    }

    public function testCannotCloseTheStyleElement(): void
    {
        $css = new CssSanitizer()->sanitize('div { color: red; } </style><script>alert(1)</script>', new MediaUrlPolicy([], false));

        self::assertStringNotContainsString('<', $css);
    }

    public function testEscapedSyntaxCharactersStayEscaped(): void
    {
        $css = new CssSanitizer()->sanitize('p::before { content: "\22 quoted\22  \3c tag"; }', new MediaUrlPolicy([], false));

        self::assertSame('p::before { content: "\22 quoted\22  \3c tag"; }', $css);
    }

    public function testDisablesScriptCapableExtensions(): void
    {
        $css = new CssSanitizer()->sanitize('div { width: expression(alert(1)); behavior: url(x.htc); -moz-binding: url(x.xml#x); }', new MediaUrlPolicy([], false));

        self::assertStringNotContainsStringIgnoringCase(' expression(', $css);
        self::assertStringContainsString('x-blocked-expression(', $css);
        self::assertStringContainsString('x-blocked-behavior:', $css);
        self::assertStringContainsString('x-blocked--moz-binding:', $css);
    }
}
