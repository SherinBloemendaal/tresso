<?php

declare(strict_types=1);

namespace App\Scan;

interface VirusScanner
{
    /** False when no scanner is configured; nothing can be scanned then. */
    public function isEnabled(): bool;

    /** Whether the scanner answers, within about a second; for health checks, never throws. */
    public function isReachable(): bool;

    /** The largest content, in bytes, the scanner accepts. */
    public function maxSize(): int;

    /**
     * Identifies the engine and signature version, recorded with every cached verdict. Asked before every scan, so a
     * scanner without signatures never hands out a clean verdict.
     *
     * @throws ScannerUnavailable
     */
    public function engine(): string;

    /**
     * Scans content streamed in chunks. Returns a clean or infected verdict, or a failed one when the content is too
     * large or the scanner could not finish it.
     *
     * @param iterable<string> $chunks
     *
     * @throws ScannerUnavailable
     */
    public function scan(iterable $chunks): ScanOutcome;
}
