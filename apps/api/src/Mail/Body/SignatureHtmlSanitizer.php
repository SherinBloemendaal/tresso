<?php

declare(strict_types=1);

namespace App\Mail\Body;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\UrlAttributeSanitizer;

/**
 * Cleans signature HTML before it is stored, so every client and the API hand out the same safe markup: inline styles
 * and presentational attributes stay; scripts, event handlers, forms, frames, style elements and linked stylesheets
 * go; images only load from https, data or cid: URLs ({@see SignatureMediaUrls}).
 */
final readonly class SignatureHtmlSanitizer
{
    /** Room for a logo or two as data URLs. */
    public const int MAX_INPUT_BYTES = 200_000;

    private HtmlSanitizer $sanitizer;

    public function __construct(CssSanitizer $css)
    {
        $config = new HtmlSanitizerConfig()
            ->allowSafeElements()
            ->allowAttribute('style', '*')
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowRelativeLinks(false)
            ->withAttributeSanitizer(new LinkAttributeSanitizer())
            ->withAttributeSanitizer(new MediaAttributeSanitizer(new SignatureMediaUrls(), $css))
            ->withMaxInputLength(self::MAX_INPUT_BYTES);
        foreach ($config->getAttributeSanitizers() as $sanitizer) {
            if ($sanitizer instanceof UrlAttributeSanitizer) {
                $config = $config->withoutAttributeSanitizer($sanitizer);
            }
        }
        foreach (MailHtmlSanitizer::PRESENTATIONAL_ATTRIBUTES as $attribute) {
            $config = $config->allowAttribute($attribute, '*');
        }

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(string $html): string
    {
        return trim($this->sanitizer->sanitize($html));
    }
}
