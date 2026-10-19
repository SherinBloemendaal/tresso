<?php

declare(strict_types=1);

namespace App\ApiResource\Output;

/** The answer to removing a file from a draft: the draft's version without it, so the device can go on writing. */
final readonly class DraftFilesOutput
{
    public function __construct(
        public int $draftVersion,
    ) {
    }
}
