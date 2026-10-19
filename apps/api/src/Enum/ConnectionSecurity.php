<?php

declare(strict_types=1);

namespace App\Enum;

enum ConnectionSecurity: string
{
    /** TLS from the first byte (IMAPS 993, SMTPS 465). */
    case Tls = 'tls';
    /** Plain connection upgraded with STARTTLS before authenticating (IMAP 143, submission 587). */
    case StartTls = 'starttls';
    /** No encryption. Only allowed for local test servers (MAIL_ALLOW_UNSAFE_SERVERS=1). */
    case None = 'none';
}
