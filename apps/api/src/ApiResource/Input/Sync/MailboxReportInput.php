<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * How a change went: `done`; `retry` when it failed for a reason that may pass (connection lost, server busy); or
 * `failed` when the server refused it. A finished move names the copy's uid in the target folder when the server
 * reported it (UIDPLUS).
 */
final class MailboxReportInput extends WorkerRequest
{
    public const string DONE = 'done';
    public const string RETRY = 'retry';
    public const string FAILED = 'failed';

    #[Assert\Choice(choices: [self::DONE, self::RETRY, self::FAILED])]
    public string $outcome = '';

    #[Assert\Length(max: 2000)]
    public ?string $error = null;

    #[Assert\Length(max: 255)]
    #[Assert\Regex(pattern: '/^[0-9]{1,18}$/', message: 'An IMAP uid is a number.')]
    public ?string $remoteUid = null;
}
