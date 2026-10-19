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
