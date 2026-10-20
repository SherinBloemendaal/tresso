<?php

declare(strict_types=1);

namespace App\Bulk;

/** The user already has a bulk action (or an undo of one) under way; one runs at a time. */
final class BulkActionRunning extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Another bulk action is still under way. Try again once it is done.');
    }
}
