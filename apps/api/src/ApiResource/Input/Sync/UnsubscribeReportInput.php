<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * How a one-click request went: `done` when the sender's server took it; `retry` when it failed for a reason that may
 * pass (busy, unreachable); `failed` when it was refused. `error` is shown to the user.
 */
final class UnsubscribeReportInput extends WorkerRequest
{
    public const string DONE = 'done';
    public const string RETRY = 'retry';
    public const string FAILED = 'failed';

    #[Assert\Choice(choices: [self::DONE, self::RETRY, self::FAILED])]
    public string $outcome = '';

    #[Assert\Length(max: 2000)]
    public ?string $error = null;
}
