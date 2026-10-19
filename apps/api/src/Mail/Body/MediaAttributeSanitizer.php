<?php

declare(strict_types=1);

namespace App\Mail\Body;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * The only judge of URLs that load media: image sources and backgrounds go through a {@see MediaUrlResolver}, inline
 * styles through the {@see CssSanitizer}. Other media attributes are dropped.
 */
final readonly class MediaAttributeSanitizer implements AttributeSanitizerInterface
{
    public const array MEDIA_ATTRIBUTES = ['src', 'background', 'lowsrc', 'poster', 'srcset'];

    public function __construct(
        private MediaUrlResolver $policy,
        private CssSanitizer $css,
    ) {
    }

    public function getSupportedElements(): ?array
    {
        return null;
    }

    /** @return list<string> */
    public function getSupportedAttributes(): array
    {
        return [...self::MEDIA_ATTRIBUTES, 'style'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        if ('style' === $attribute) {
            $style = trim($this->css->sanitize($value, $this->policy));

            return '' === $style ? null : $style;
        }

        return match (true) {
            'src' === $attribute && 'img' === $element, 'background' === $attribute => $this->policy->resolve($value),
            default => null,
        };
    }
}
