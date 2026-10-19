<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a calendar lives: only in Tresso, or at the provider of a mail account.
 */
enum CalendarSource: string
{
    case Local = 'local';
    case CalDav = 'caldav';
    case Google = 'google';
    case Microsoft = 'microsoft';
}
