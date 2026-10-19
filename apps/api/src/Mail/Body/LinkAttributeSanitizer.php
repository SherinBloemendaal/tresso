<?php

declare(strict_types=1);

namespace App\Mail\Body;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\UrlAttributeSanitizer;

/**
 * The sanitizer's own URL checks, for every URL attribute except the ones that load media: those are decided by the
 * {@see MediaAttributeSanitizer} alone, because the stock checks reject cid: references before they can be resolved.
 */
final readonly class LinkAttributeSanitizer implements AttributeSanitizerInterface
{
    private UrlAttributeSanitizer $urls;

    public function __construct()
    {
        $this->urls = new UrlAttributeSanitizer();
    }

    public function getSupportedElements(): ?array
    {
        return $this->urls->getSupportedElements();
    }

    /** @return list<string> */
    public function getSupportedAttributes(): array
    {
        return array_values(array_diff((array) $this->urls->getSupportedAttributes(), MediaAttributeSanitizer::MEDIA_ATTRIBUTES));
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return $this->urls->sanitizeAttribute($element, $attribute, $value, $config);
    }
}
