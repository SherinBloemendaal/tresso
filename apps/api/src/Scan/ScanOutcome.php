<?php

declare(strict_types=1);

namespace App\Scan;

use App\Enum\ScanFailure;
use App\Enum\ScanStatus;

/**
 * The verdict for one piece of content: clean, infected (with the signature that matched) or failed (with the reason).
 */
final readonly class ScanOutcome
{
    private function __construct(
        public ScanStatus $status,
        public ?string $signature = null,
        public ?ScanFailure $failure = null,
    ) {
    }

    public static function clean(): self
    {
        return new self(ScanStatus::Clean);
    }

    public static function infected(string $signature): self
    {
        if ('' === $signature) {
            throw new \InvalidArgumentException('An infected verdict needs the signature that matched.');
        }

        return new self(ScanStatus::Infected, $signature);
    }

    public static function failed(ScanFailure $failure): self
    {
        return new self(ScanStatus::Failed, failure: $failure);
    }

    /** Clean and infected verdicts hold for the content until the signatures change; failures are worth retrying. */
    public function isDefinitive(): bool
    {
        return ScanStatus::Clean === $this->status || ScanStatus::Infected === $this->status;
    }
}
