<?php

declare(strict_types=1);

namespace App\Storage;

/** How far re-encrypting one user got. */
final readonly class ReencryptionResult
{
    /**
     * @param list<int> $retired  key versions deleted because nothing uses them any more
     * @param list<int> $versions key versions the user still has
     */
    public function __construct(
        public int $currentVersion,
        public int $failed,
        public int $left,
        public array $retired,
        public array $versions,
    ) {
    }

    /** Whether all of the user's data is on the current version and the older versions are gone. */
    public function isComplete(): bool
    {
        return 0 === $this->left && [$this->currentVersion] === $this->versions;
    }
}
