<?php

declare(strict_types=1);

namespace App\Tests\Unit\Outbox;

use App\Outbox\OutgoingHtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class OutgoingHtmlSanitizerTest extends TestCase
{
    private OutgoingHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new OutgoingHtmlSanitizer();
    }

    public function testKeepsImagesThatGoAlongInPlace(): void
    {
        $html = $this->sanitizer->sanitize('<table background="cid:bg@x.example"><tr><td><img src="cid:logo@finance.example" alt="Logo"></td></tr></table>');

        self::assertStringContainsString('background="cid:bg@x.example"', $html);
        self::assertStringContainsString('src="cid:logo@finance.example"', $html);
    }

    public function testKeepsAnImageAnInlineStyleShows(): void
    {
        $html = $this->sanitizer->sanitize('<table><tr><td style="background-image:url(&quot;cid:ii_hero&quot;)">x</td></tr></table><p style="background:url(cid:bg@x)">y</p>', ['ii_hero' => 'h1@inline.tresso.invalid']);

        self::assertStringContainsString('url(&#34;cid:h1@inline.tresso.invalid&#34;)', $html);
        self::assertStringContainsString('url(cid:bg@x)', $html);
    }

    public function testReadsAStyleUrlWithHexQuotes(): void
    {
        $html = $this->sanitizer->sanitize('<p style="background:url(&#x22;cid:ii_hex&#x22;)">x</p>', ['ii_hex' => 'h3@inline.tresso.invalid']);

        self::assertStringContainsString('cid:h3@inline.tresso.invalid', $html);
    }

    public function testReadsAnIdWithAnAmpersandWhole(): void
    {
        self::assertSame('<img src="cid:h2@inline.tresso.invalid" />', $this->sanitizer->sanitize('<img src="cid:a&amp;b">', ['a&b' => 'h2@inline.tresso.invalid']));
    }

    public function testRefersToAnImageByTheIdItGoesOutUnder(): void
    {
        self::assertSame('<img src="cid:a1b2@inline.tresso.invalid" />', $this->sanitizer->sanitize('<img src="cid:ii_mgx81bq20">', ['ii_mgx81bq20' => 'a1b2@inline.tresso.invalid']));
    }

    public function testRemovesCidLinksAndScripts(): void
    {
        $html = $this->sanitizer->sanitize('<a href="cid:bar@x">Link</a><script src="cid:x@y"></script><p>About cid:note@x</p><table><tr><td background="javascript:alert(1)">x</td></tr></table>');

        self::assertSame('<a>Link</a><p>About cid:note&#64;x</p><table><tbody><tr><td>x</td></tr></tbody></table>', $html);
    }
}
