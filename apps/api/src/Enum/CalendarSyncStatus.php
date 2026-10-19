<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How syncing the calendars of a mail account goes, apart from its mail.
 */
enum CalendarSyncStatus: string
{
    /** Not looked for yet. */
    case Unknown = 'unknown';
    case Active = 'active';
    /** The server offers no CalDAV (looked for again daily). */
    case Unsupported = 'unsupported';
    case AuthFailed = 'auth_failed';
    /** A server was found that does not get the password until the user confirms its address. */
    case Unconfirmed = 'unconfirmed';
    case Error = 'error';
}
