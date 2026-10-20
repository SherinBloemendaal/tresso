<?php

declare(strict_types=1);

namespace App\Mail\Body;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * How a stored file is handed out, received or attached to a draft: common images inline, everything else as a
 * download, never sniffed, sandboxed and without a referrer.
 */
final class AttachmentResponse
{
    private const array INLINE_TYPES = ['image/png', 'image/gif', 'image/jpeg', 'image/webp', 'image/bmp', 'image/avif'];

    /**
     * @param iterable<string> $chunks
     */
    public static function stream(iterable $chunks, string $filename, string $contentType, string $sha256): StreamedResponse
    {
        $contentType = strtolower($contentType);
        $inline = \in_array($contentType, self::INLINE_TYPES, true);

        $response = new StreamedResponse(static function () use ($chunks): void {
            foreach ($chunks as $chunk) {
                echo $chunk;
                flush();
            }
        });
        $response->headers->set('Content-Type', $inline ? $contentType : 'application/octet-stream');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $filename,
            self::asciiFallback($filename),
        ));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        $response->headers->set('Cross-Origin-Resource-Policy', 'cross-origin');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        // Never cached: a later verdict (malware found by a rescan) must take effect on the next request.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->setEtag($sha256);

        return $response;
    }

    private static function asciiFallback(string $filename): string
    {
        $ascii = (string) preg_replace('~[^\x20-\x7e]|[%/\\\\"]~u', '_', $filename);

        return '' === trim($ascii, '_ ') ? 'attachment' : $ascii;
    }
}
