<?php

declare(strict_types=1);

namespace App\Enum;

enum MailAccountStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case AuthFailed = 'auth_failed';
    case Error = 'error';
}
