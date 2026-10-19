<?php

declare(strict_types=1);

namespace App\Mail\Body;

/**
 * Makes a message's CSS safe to render: every url() goes through the {@see MediaUrlPolicy}, imports and the old
 * script-capable extensions are disabled, and the result can never close the style element it is placed in.
 *
 * CSS escapes are resolved first, so an escaped name such as "u\72l(" cannot slip past the checks; escapes that
 * stand for syntax characters are kept, escaped. The client renders mail with a Content-Security-Policy as well, so
 * this is the first of two layers.
 */
final class CssSanitizer
{
    /** Characters an escape may not turn into, because they change the structure of the CSS. */
    private const string STRUCTURAL = "\"'\\()<>;:{}/\n\r\f\t ";

    public function sanitize(string $css, MediaUrlPolicy $policy): string
    {
        $css = (string) preg_replace('~/\*.*?(?:\*/|$)~s', '', $css);
        $css = $this->resolveEscapes($css);
        $css = (string) preg_replace('~@(?:import|charset|namespace)\b[^;{}]*;?~i', '', $css);
        $css = (string) preg_replace('~(expression|behavior|-moz-binding|(?:-webkit-)?image-set|javascript|vbscript)\s*([:(])~i', 'x-blocked-$1$2', $css);
        $css = (string) preg_replace_callback(
            '~url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)]*?))\s*\)~i',
            static function (array $match) use ($policy): string {
                $url = $policy->resolve($match[1].($match[2] ?? '').($match[3] ?? ''));

                return null === $url ? 'none' : 'url("'.self::escapeString($url).'")';
            },
            $css,
        );

        return str_replace('<', '\\3c ', $css);
    }

    private function resolveEscapes(string $css): string
    {
        return (string) preg_replace_callback(
            '~\\\\(?:([0-9a-f]{1,6})[ \t\n\r\f]?|(.))~isu',
            static function (array $match): string {
                $codepoint = null !== $match[1] ? (int) hexdec($match[1]) : mb_ord((string) $match[2], 'UTF-8');
                $char = $codepoint > 0 && $codepoint <= 0x10FFFF ? mb_chr($codepoint, 'UTF-8') : false;
                if (!\is_string($char) || str_contains(self::STRUCTURAL, $char) || 1 === preg_match('~\p{C}~u', $char)) {
                    return \sprintf('\\%x ', $codepoint);
                }

                return $char;
            },
            $css,
            flags: \PREG_UNMATCHED_AS_NULL,
        );
    }

    private static function escapeString(string $value): string
    {
        return addcslashes($value, "\"\\\n\r");
    }
}
