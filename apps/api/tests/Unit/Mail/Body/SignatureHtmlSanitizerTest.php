<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail\Body;

use App\Mail\Body\CssSanitizer;
use App\Mail\Body\SignatureHtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class SignatureHtmlSanitizerTest extends TestCase
{
    private SignatureHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new SignatureHtmlSanitizer(new CssSanitizer());
    }

    public function testKeepsInlineStylesAndTableLayout(): void
    {
        $html = '<table cellpadding="0" cellspacing="0" style="font-family: Arial, sans-serif; font-size: 13px"><tr><td style="font-weight: 700">Kim Visser</td></tr></table>';

        self::assertSame(
            '<table cellpadding="0" cellspacing="0" style="font-family: Arial, sans-serif; font-size: 13px"><tbody><tr><td style="font-weight: 700">Kim Visser</td></tr></tbody></table>',
            $this->sanitizer->sanitize($html),
        );
    }

    public function testRemovesScriptsHandlersFormsAndFrames(): void
    {
        $html = $this->sanitizer->sanitize(
            '<p onclick="steal()" onmouseover="steal()">Kim</p><script>steal()</script><form action="https://x.example"><input name="p"></form>'
            .'<iframe src="https://x.example"></iframe><object data="x.swf"></object><svg onload="steal()"></svg>',
        );

        self::assertSame('<p>Kim</p>', $html);
    }

    public function testRemovesStyleElementsAndLinkedStylesheets(): void
    {
        $html = $this->sanitizer->sanitize('<link rel="stylesheet" href="https://cdn.example/s.css"><style>p { color: red }</style><p style="color: red">Kim</p>');

        self::assertSame('<p style="color: red">Kim</p>', $html);
    }

    public function testRemovesScriptLinksButKeepsWebMailAndPhoneLinks(): void
    {
        $html = $this->sanitizer->sanitize(
            '<a href="javascript:steal()">x</a><a href="https://company.example">site</a><a href="mailto:kim@company.example">mail</a><a href="tel:+31201234567">call</a>',
        );

        self::assertSame('<a>x</a><a href="https://company.example">site</a><a href="mailto:kim&#64;company.example">mail</a><a href="tel:&#43;31201234567">call</a>', $html);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function imageSources(): iterable
    {
        yield 'https' => ['https://cdn.company.example/logo.png', 'https://cdn.company.example/logo.png'];
        yield 'data image' => ['data:image/png;base64,iVBORw0KGgo=', 'data:image/png;base64,iVBORw0KGgo&#61;'];
        yield 'cid reference' => ['cid:logo@company.example', 'cid:logo&#64;company.example'];
        yield 'plain http' => ['http://cdn.company.example/logo.png', null];
        yield 'data svg' => ['data:image/svg+xml;base64,PHN2Zz4=', null];
        yield 'data html' => ['data:text/html;base64,PHNjcmlwdD4=', null];
        yield 'relative' => ['/logo.png', null];
        yield 'javascript' => ['javascript:steal()', null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('imageSources')]
    public function testImagesOnlyFromHttpsDataOrCid(string $source, ?string $kept): void
    {
        $html = $this->sanitizer->sanitize('<img src="'.$source.'" alt="Logo">');

        self::assertSame(null === $kept ? '<img alt="Logo" />' : '<img src="'.$kept.'" alt="Logo" />', $html);
    }

    public function testStyleUrlsFollowTheImageRules(): void
    {
        $html = $this->sanitizer->sanitize(
            '<div style="background-image: url(http://tracker.example/bg.png)">a</div><div style="background: url(\'https://cdn.example/bg.png\')">b</div>',
        );

        self::assertSame('<div style="background-image: none">a</div><div style="background: url(&#34;https://cdn.example/bg.png&#34;)">b</div>', $html);
    }

    public function testLongInputIsNotCutShort(): void
    {
        $logo = 'data:image/png;base64,'.str_repeat('A', 150_000);

        self::assertStringContainsString($logo, $this->sanitizer->sanitize('<img src="'.$logo.'">'));
    }
}
