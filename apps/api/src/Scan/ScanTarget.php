<?php

declare(strict_types=1);

namespace App\Scan;

/** Which kind of attachment a scan job is about. */
enum ScanTarget: string
{
    /** An attachment of received (synced) mail. */
    case Received = 'received';
    /** A file attached to a draft: uploaded, or taken from received mail by a forward. */
    case Draft = 'draft';
}
