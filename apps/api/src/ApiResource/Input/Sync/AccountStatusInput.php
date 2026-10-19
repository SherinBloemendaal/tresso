<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync;

use App\Enum\MailAccountStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the worker learned about the account: it works (again), the login was refused, or something else failed.
 */
final class AccountStatusInput extends WorkerRequest
{
    #[Assert\NotNull]
    #[Assert\Choice(choices: [MailAccountStatus::Active, MailAccountStatus::AuthFailed, MailAccountStatus::Error])]
    public ?MailAccountStatus $status = null;

    /** Short, user-safe description of the problem (never server output with secrets). */
    #[Assert\Length(max: 500)]
    public ?string $detail = null;
}
