<?php

declare(strict_types=1);

namespace App\Mail\Body;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\UrlAttributeSanitizer;

/**
 * Turns the HTML of a received message into markup that is safe to show: no scripts, event handlers, forms, frames or
 * embedded objects, links that open outside the app, and images and styles limited by the {@see MediaUrlPolicy}.
 *
 * Mail layouts lean on style elements and old presentational attributes, so both survive: the style elements'
 * CSS is sanitized separately and placed in front of the sanitized body.
 */
final readonly class MailHtmlSanitizer
{
    /** Large newsletters exceed the sanitizer's default limit of 20 000 characters by far. */
    private const int MAX_INPUT_BYTES = 8_000_000;

    public const array PRESENTATIONAL_ATTRIBUTES = [
        'align', 'valign', 'bgcolor', 'background', 'width', 'height', 'border', 'cellpadding', 'cellspacing',
        'color', 'face', 'size', 'dir', 'lang', 'colspan', 'rowspan', 'nowrap', 'title', 'alt', 'role', 'class', 'id',
    ];

    public function __construct(private CssSanitizer $css)
    {
    }

    public function sanitize(string $html, MediaUrlPolicy $policy): string
    {
        $config = new HtmlSanitizerConfig()
            ->allowSafeElements()
            ->allowAttribute('style', '*')
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowRelativeLinks(false)
            ->withAttributeSanitizer(new LinkAttributeSanitizer())
            ->forceAttribute('a', 'target', '_blank')
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->withAttributeSanitizer(new MediaAttributeSanitizer($policy, $this->css))
            ->withMaxInputLength(self::MAX_INPUT_BYTES);
        foreach ($config->getAttributeSanitizers() as $sanitizer) {
            if ($sanitizer instanceof UrlAttributeSanitizer) {
                $config = $config->withoutAttributeSanitizer($sanitizer);
            }
        }
        foreach (self::PRESENTATIONAL_ATTRIBUTES as $attribute) {
            $config = $config->allowAttribute($attribute, '*');
        }

        $body = new HtmlSanitizer($config)->sanitize($html);
        $styles = $this->styles($html, $policy);

        return '' === $styles ? $body : '<style>'.$styles.'</style>'.$body;
    }

    private function styles(string $html, MediaUrlPolicy $policy): string
    {
        if (false === stripos($html, '<style')) {
            return '';
        }

        $document = \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR);
        $styles = [];
        foreach ($document->querySelectorAll('style') as $style) {
            $css = trim($this->css->sanitize((string) $style->textContent, $policy));
            if ('' !== $css) {
                $styles[] = $css;
            }
        }

        return implode("\n", $styles);
    }
}
