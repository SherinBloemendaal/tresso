<?php

declare(strict_types=1);

namespace App\Outbox;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Cleans what the user wrote before it goes out: formatting, links, tables and images stay; scripts, handlers, forms
 * and frames never leave Tresso, whatever a client or API caller sends.
 */
final readonly class OutgoingHtmlSanitizer
{
    private const int MAX_INPUT_BYTES = 2_000_000;

    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = new HtmlSanitizerConfig()
            ->allowSafeElements()
            ->allowAttribute('style', '*')
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowRelativeLinks(false)
            ->allowMediaSchemes(['https', 'data', 'cid'])
            ->allowRelativeMedias(false)
            ->withMaxInputLength(self::MAX_INPUT_BYTES);
        foreach (['align', 'valign', 'bgcolor', 'width', 'height', 'border', 'cellpadding', 'cellspacing', 'colspan', 'rowspan', 'dir', 'lang', 'title', 'alt'] as $attribute) {
            $config = $config->allowAttribute($attribute, '*');
        }
        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(string $html): string
    {
        return $this->sanitizer->sanitize($html);
    }

    /** The plain-text alternative: paragraphs and line breaks kept, links written out. */
    public function toText(string $html): string
    {
        $document = \Dom\HTMLDocument::createFromString('<!doctype html><body>'.$html.'</body>', \LIBXML_NOERROR);
        $body = $document->body;
        if (null === $body) {
            return '';
        }
        $text = $this->text($body);
        $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text)."\n";
    }

    private function text(\Dom\Node $node): string
    {
        if ($node instanceof \Dom\Text) {
            return (string) preg_replace('/\s+/u', ' ', (string) $node->textContent);
        }
        if (!$node instanceof \Dom\Element) {
            return '';
        }
        $name = strtolower($node->localName);
        if ('br' === $name) {
            return "\n";
        }
        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= $this->text($child);
        }

        return match ($name) {
            'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'ul', 'ol', 'blockquote', 'pre' => "\n".trim($inner)."\n\n",
            'tr' => trim($inner)."\n",
            'td', 'th' => trim($inner).' ',
            'li' => '- '.trim($inner)."\n",
            'a' => $this->link($node, $inner),
            default => $inner,
        };
    }

    private function link(\Dom\Element $anchor, string $label): string
    {
        $href = (string) $anchor->getAttribute('href');
        $label = trim($label);
        if ('' === $href || $href === $label || 'mailto:'.$label === $href) {
            return $label;
        }

        return '' === $label ? $href : \sprintf('%s (%s)', $label, $href);
    }
}
