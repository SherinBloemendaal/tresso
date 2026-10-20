<?php

declare(strict_types=1);

namespace App\Scan;

/**
 * Brings object storage in line with the verdict for a piece of content, after that verdict is committed: infected
 * content leaves every live key for quarantine, and content a rescan cleared goes back. Safe to send any number of
 * times; it retries on its own when storage is unavailable, while downloads already follow the committed verdict.
 */
final readonly class PlaceContent
{
    public function __construct(public string $sha256)
    {
    }
}
