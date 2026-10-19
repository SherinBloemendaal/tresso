<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * How a change went: `done`; `retry` when it failed for a reason that may pass (server busy); `failed` when the
 * server refused it; or, for a move, `gone` when the copy was no longer in its folder. A finished move names the
 * copy's uid in the target folder when the worker learnt it.
 */
final class MailboxReportInput extends WorkerRequest
{
    public const string DONE = 'done';
    public const string RETRY = 'retry';
    public const string FAILED = 'failed';
    public const string GONE = 'gone';

    #[Assert\Choice(choices: [self::DONE, self::RETRY, self::FAILED, self::GONE])]
    public string $outcome = '';

    #[Assert\Length(max: 2000)]
    public ?string $error = null;

    #[Assert\Length(max: 255)]
    #[Assert\Regex(pattern: '/^[0-9]{1,18}$/', message: 'An IMAP uid is a number.')]
    public ?string $remoteUid = null;
}
