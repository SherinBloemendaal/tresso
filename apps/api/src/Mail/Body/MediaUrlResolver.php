<?php

declare(strict_types=1);

namespace App\Mail\Body;

/**
 * Decides what an image or background URL in HTML or CSS becomes.
 */
interface MediaUrlResolver
{
    /** The URL to keep, possibly rewritten, or null to remove it. */
    public function resolve(string $url): ?string;
}
