<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Access to the object storage the sync workers write raw messages and attachments to.
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

    /** Stores the object; content-addressed keys make writing it twice harmless. */
    public function write(string $key, string $content, string $contentType): void;

    /**
     * Copies an object to another key, replacing what is there.
     *
     * @throws ObjectNotFound when there is nothing at $from
     */
    public function copy(string $from, string $to): void;

    /**
     * Moves an object to another key, replacing what is there.
     *
     * @throws ObjectNotFound when there is nothing at $from
     */
    public function move(string $from, string $to): void;

    /** Removes the object; one that is gone already is fine. */
    public function delete(string $key): void;
}
