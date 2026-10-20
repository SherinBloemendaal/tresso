<?php

declare(strict_types=1);

namespace App\Scan;

use App\Storage\BlobVault;
use App\Storage\ObjectNotFound;

/** Reads the content a scan is about, decrypted, wherever it is now. */
final class ScanContent
{
    /**
     * Content that was quarantined before is still read there, so a rescan can clear it.
     *
     * @return iterable<string>
     *
     * @throws ObjectNotFound
     */
    public static function stream(BlobVault $blobs, ScanSubject $subject): iterable
    {
        foreach ($subject->keys as $key) {
            try {
                return $blobs->stream($subject->owner, $key);
            } catch (ObjectNotFound) {
                continue;
            }
        }

        throw ObjectNotFound::forKey($subject->keys[0]);
    }
}
