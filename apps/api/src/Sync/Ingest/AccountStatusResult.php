<?php

declare(strict_types=1);

namespace App\Sync\Ingest;

use App\Enum\MailAccountStatus;

final readonly class AccountStatusResult
{
    public function __construct(public MailAccountStatus $status)
    {
    }
}
