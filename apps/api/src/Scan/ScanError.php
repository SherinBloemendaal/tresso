<?php

declare(strict_types=1);

namespace App\Scan;

/**
 * clamd answered, but with an error (out of memory, a read timeout on its side). Often temporary, so the scan is tried
 * again; when it keeps failing, the attachment is marked unscannable.
 */
final class ScanError extends ScannerUnavailable
{
}
