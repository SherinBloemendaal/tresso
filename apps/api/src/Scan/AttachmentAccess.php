<?php

declare(strict_types=1);

namespace App\Scan;

/** What a client may do with an attachment's content, from its scan. */
enum AttachmentAccess: string
{
    /** Scanned and clean: the content has a link. */
    case Allowed = 'allowed';
    /** Still being scanned: no link yet. */
    case Scanning = 'scanning';
    /** Could not be scanned: a link only after the user confirms they accept that (see {@see DownloadPolicy}). */
    case Confirm = 'confirm';
    /** Infected, or not scanned on a server that requires scans: never. */
    case Blocked = 'blocked';
}
