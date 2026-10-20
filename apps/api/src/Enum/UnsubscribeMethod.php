<?php

declare(strict_types=1);

namespace App\Enum;

enum UnsubscribeMethod: string
{
    /** RFC 8058: an HTTPS POST of `List-Unsubscribe=One-Click`, made by the sync worker. */
    case OneClick = 'oneClick';
    /** RFC 2369: a mail to the list's `mailto:` address, sent through the outbox. */
    case Mailto = 'mailto';
}
