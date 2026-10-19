<?php

declare(strict_types=1);

namespace App\Sync\Mailbox;

final readonly class MailboxReportResult
{
    public function __construct(public bool $accepted)
    {
    }
}
