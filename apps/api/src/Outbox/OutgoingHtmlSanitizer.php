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

    /** Stands in for a cid: reference while the HTML is cleaned: the sanitizer drops URLs without a host. */
    private const string CID_STAND_IN = 'https://cid.tresso.invalid/';

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
        foreach (['align', 'valign', 'bgcolor', 'background', 'width', 'height', 'border', 'cellpadding', 'cellspacing', 'colspan', 'rowspan', 'dir', 'lang', 'title', 'alt'] as $attribute) {
            $config = $config->allowAttribute($attribute, '*');
        }
        $this->sanitizer = new HtmlSanitizer($config);
    }

    /**
     * The HTML cleaned. Images referred to as cid:{id} keep that reference, through `$contentIds` when the part goes out
     * under another id (one a mail header can carry).
     *
     * @param array<string, string> $contentIds
     */
    public function sanitize(string $html, array $contentIds = []): string
    {
        $standIns = (string) preg_replace_callback(
            // Only where an image is shown (src, background, url() in a style): a cid: link stays one the cleaner removes.
            '~(\s(?:src|background)\s*=\s*["\']?|url\(\s*(?:["\']|&(?:quot|apos|#0*3[49]|#x0*2[27]);)?)cid:((?:(?!&(?:quot|apos|#0*3[49]|#x0*2[27]);)[^"\'\s<>()])+)~i',
            static fn (array $match): string => $match[1].self::CID_STAND_IN.rawurlencode(html_entity_decode($match[2], \ENT_QUOTES | \ENT_HTML5, 'UTF-8')),
            $html,
        );
        $clean = $this->sanitizer->sanitize($standIns);

        return (string) preg_replace_callback(
            '#'.preg_quote(self::CID_STAND_IN, '#').'([A-Za-z0-9%._~-]+)#',
            static function (array $match) use ($contentIds): string {
                $id = rawurldecode($match[1]);

                return 'cid:'.htmlspecialchars($contentIds[$id] ?? $id, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
            },
            $clean,
        );
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
