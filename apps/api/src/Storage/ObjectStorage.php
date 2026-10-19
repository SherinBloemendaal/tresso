<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Read access to the object storage the sync workers write raw messages and attachments to.
 */
interface ObjectStorage
{
    /**
     * @throws ObjectNotFound
     */
    public function read(string $key): string;

    /**
     * Opens the object for streaming. Missing objects fail here, before any chunk is read.
     *
     * @return iterable<string>
     *
     * @throws ObjectNotFound
     */
    public function stream(string $key): iterable;
}
