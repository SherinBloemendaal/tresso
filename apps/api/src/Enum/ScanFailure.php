<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Why an attachment could not be scanned.
 */
enum ScanFailure: string
{
    /** Larger than the scanner accepts (CLAMAV_MAX_SIZE, or clamd's own StreamMaxLength). */
    case TooLarge = 'too_large';
    /**
     * The scanner could not check the content: it is encrypted or past clamd's own scan limits, or clamd kept
     * answering with an error.
     */
    case Unscannable = 'unscannable';
    /** The scanner or the stored content stayed out of reach after every retry. */
    case Unavailable = 'unavailable';
    /** Scanning is turned off (no CLAMAV_HOST). */
    case Disabled = 'disabled';
}
