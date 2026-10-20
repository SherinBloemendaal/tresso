<?php

declare(strict_types=1);

namespace App\Scan;

/**
 * The scanner could not be reached or stopped answering. Worth retrying later.
 */
class ScannerUnavailable extends \RuntimeException
{
}
