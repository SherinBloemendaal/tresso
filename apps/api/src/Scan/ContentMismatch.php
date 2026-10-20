<?php

declare(strict_types=1);

namespace App\Scan;

/** Stored content is not what its fingerprint says. */
final class ContentMismatch extends \RuntimeException
{
}
