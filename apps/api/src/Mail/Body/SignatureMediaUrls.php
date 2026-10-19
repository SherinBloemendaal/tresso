<?php

declare(strict_types=1);

namespace App\Mail\Body;

/**
 * Images a signature may use: from https, inline as data, or a cid: reference to a part sent along with the message.
 * Plain http is refused because the signature goes out in every message, and mixed content breaks in many clients.
 */
final readonly class SignatureMediaUrls implements MediaUrlResolver
{
    private const string CONTENT_ID = '~^cid:[^\s"\'<>()\\\\]+$~i';

    public function resolve(string $url): ?string
    {
        $url = trim($url);

        return match (strtolower((string) strstr($url, ':', true))) {
            'https' => false === filter_var($url, \FILTER_VALIDATE_URL) ? null : $url,
            'data' => 1 === preg_match(MediaUrlPolicy::DATA_IMAGE, $url) ? $url : null,
            'cid' => 1 === preg_match(self::CONTENT_ID, $url) ? $url : null,
            default => null,
        };
    }
}
