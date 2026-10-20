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

    /**
     * Stores the object, replacing what is there. A resource is read from its current position to its end.
     *
     * @param string|resource       $content
     * @param array<string, string> $metadata stored with the object (S3 user metadata)
     */
    public function write(string $key, mixed $content, string $contentType, array $metadata = []): void;

    public function exists(string $key): bool;

    /**
     * Every key that starts with $prefix, in key order.
     *
     * @return iterable<string>
     */
    public function list(string $prefix): iterable;

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
