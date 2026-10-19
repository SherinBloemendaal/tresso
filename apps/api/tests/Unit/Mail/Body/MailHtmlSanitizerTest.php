<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail\Body;

use App\Mail\Body\CssSanitizer;
use App\Mail\Body\MailHtmlSanitizer;
use App\Mail\Body\MediaUrlPolicy;
use PHPUnit\Framework\TestCase;

final class MailHtmlSanitizerTest extends TestCase
{
    private MailHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new MailHtmlSanitizer(new CssSanitizer());
    }

    public function testRemovesScriptsHandlersFormsAndFrames(): void
    {
        $html = $this->sanitizer->sanitize(
            '<p onclick="steal()">Hi</p><script>steal()</script><form action="https://x.example"><input name="p"></form>'
            .'<iframe src="https://x.example"></iframe><object data="x.swf"></object><svg onload="steal()"></svg>',
            new MediaUrlPolicy([], false),
        );

        self::assertSame('<p>Hi</p>', $html);
    }

    public function testRemovesScriptLinks(): void
    {
        $html = $this->sanitizer->sanitize('<a href="javascript:steal()">x</a><a href="  JaVaScRiPt:steal()">y</a>', new MediaUrlPolicy([], false));

        self::assertStringNotContainsStringIgnoringCase('javascript', $html);
    }

    public function testLinksOpenOutsideTheApp(): void
    {
        $html = $this->sanitizer->sanitize('<a href="https://example.test/offer">Offer</a>', new MediaUrlPolicy([], false));

        self::assertSame('<a href="https://example.test/offer" target="_blank" rel="noopener noreferrer">Offer</a>', $html);
    }

    public function testBlocksRemoteImagesByDefault(): void
    {
        $policy = new MediaUrlPolicy([], false);

        $html = $this->sanitizer->sanitize('<img src="https://tracker.example/open.gif" alt="logo"><td background="http://tracker.example/bg.gif">', $policy);

        self::assertStringNotContainsString('tracker.example', $html);
        self::assertStringContainsString('alt="logo"', $html);
        self::assertTrue($policy->remoteContentFound());
    }

    public function testLoadsRemoteImagesWhenAllowed(): void
    {
        $html = $this->sanitizer->sanitize('<img src="https://cdn.example/logo.png">', new MediaUrlPolicy([], true));

        self::assertStringContainsString('src="https://cdn.example/logo.png"', $html);
    }

    public function testDropsOtherMediaAttributes(): void
    {
        $html = $this->sanitizer->sanitize('<img src="https://cdn.example/a.png" srcset="https://tracker.example/a.png 2x" lowsrc="https://tracker.example/b.png"><video poster="https://tracker.example/c.png"></video>', new MediaUrlPolicy([], true));

        self::assertStringNotContainsString('tracker.example', $html);
    }

    public function testNoRemoteContentIsReportedAsSuch(): void
    {
        $policy = new MediaUrlPolicy([], false);

        $this->sanitizer->sanitize('<p>Plain</p>', $policy);

        self::assertFalse($policy->remoteContentFound());
    }

    public function testServesInlineImagesFromTheirSignedUrl(): void
    {
        $html = $this->sanitizer->sanitize('<img src="cid:logo@finance"><img src="cid:unknown@x">', new MediaUrlPolicy(['logo@finance' => 'https://api.example/content/attachments/a1?_hash=x'], false));

        self::assertSame('<img src="https://api.example/content/attachments/a1?_hash&#61;x" /><img />', $html);
    }

    public function testKeepsDataImagesButNotOtherDataUrls(): void
    {
        $html = $this->sanitizer->sanitize('<img src="data:image/png;base64,iVBORw0KGgo="><img src="data:text/html;base64,PHNjcmlwdD4=">', new MediaUrlPolicy([], false));

        self::assertSame('<img src="data:image/png;base64,iVBORw0KGgo&#61;" /><img />', $html);
    }

    public function testKeepsLayoutAttributesAndSanitizedInlineStyles(): void
    {
        $html = $this->sanitizer->sanitize(
            '<table width="600" cellpadding="0" bgcolor="#ffffff"><tr><td align="center" style="color: #333; background: url(https://tracker.example/x.gif)">Hi</td></tr></table>',
            new MediaUrlPolicy([], false),
        );

        self::assertStringContainsString('width="600"', $html);
        self::assertStringContainsString('cellpadding="0"', $html);
        self::assertStringContainsString('bgcolor="#ffffff"', $html);
        self::assertStringContainsString('align="center"', $html);
        self::assertStringContainsString('style="color: #333; background: none"', $html);
    }

    public function testKeepsStyleElementsSanitizedInFront(): void
    {
        $html = $this->sanitizer->sanitize(
            '<html><head><style>.btn { background: url(https://tracker.example/b.png); color: red; }</style></head><body><p class="btn">Go</p></body></html>',
            new MediaUrlPolicy([], false),
        );

        self::assertSame('<style>.btn { background: none; color: red; }</style><p class="btn">Go</p>', $html);
    }

    public function testHandlesLargeNewsletters(): void
    {
        $html = $this->sanitizer->sanitize(str_repeat('<p>Paragraph of a long newsletter.</p>', 5_000), new MediaUrlPolicy([], false));

        self::assertSame(5_000, substr_count($html, '<p>'));
    }
}
