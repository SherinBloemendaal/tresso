<?php

declare(strict_types=1);

namespace App\Outbox;

/**
 * The HTML of a draft as it would be sent: the text, the signature, the quoted original. Mirrors composeBody() in
 * packages/core, which clients use when they send.
 */
final class DraftBody
{
    public static function compose(string $html, ?string $signatureHtml, string $quoteHtml): string
    {
        $body = $html;
        if (null !== $signatureHtml && '' !== trim($signatureHtml)) {
            $body .= '<div class="tresso-signature">'.$signatureHtml.'</div>';
        }
        if ('' !== trim($quoteHtml)) {
            $body .= '<div class="tresso-quote">'.$quoteHtml.'</div>';
        }

        return $body;
    }
}
