<?php

declare(strict_types=1);

namespace App\Mail\Body;

/**
 * Decides what every image or background URL in a message becomes, and remembers whether the message wanted to load
 * anything from the internet. Remote loads reveal to the sender that, when and where a message was read, so they
 * are removed unless the reader allowed them.
 */
final class MediaUrlPolicy implements MediaUrlResolver
{
    /** Inline images in the formats every mail client shows; never SVG, which can carry scripts. */
    public const string DATA_IMAGE = '~^data:image/(?:png|gif|jpe?g|webp|bmp|avif);base64,[a-z0-9+/=\s]+$~i';

    private bool $remoteContentFound = false;

    /**
     * @param array<string, string> $contentIdUrls URL to serve each inline part from, by lower-cased Content-ID
     */
    public function __construct(
        private readonly array $contentIdUrls,
        private readonly bool $allowRemoteContent,
    ) {
    }

    public function resolve(string $url): ?string
    {
        $url = trim($url);
        $scheme = strtolower((string) strstr($url, ':', true));

        return match ($scheme) {
            'cid' => $this->contentIdUrls[self::normalizeContentId(substr($url, 4))] ?? null,
            'data' => 1 === preg_match(self::DATA_IMAGE, $url) ? $url : null,
            'http', 'https' => $this->remote($url),
            default => null,
        };
    }

    public function remoteContentFound(): bool
    {
        return $this->remoteContentFound;
    }

    public static function normalizeContentId(string $contentId): string
    {
        return strtolower(trim(rawurldecode($contentId), "<> \t"));
    }

    private function remote(string $url): ?string
    {
        $this->remoteContentFound = true;

        return $this->allowRemoteContent ? $url : null;
    }
}
