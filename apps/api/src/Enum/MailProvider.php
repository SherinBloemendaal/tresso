<?php

declare(strict_types=1);

namespace App\Enum;

enum MailProvider: string
{
    case Imap = 'imap';
    case Gmail = 'gmail';
    case Microsoft = 'microsoft';
}
