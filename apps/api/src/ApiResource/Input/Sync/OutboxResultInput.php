<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * How sending went: sent; retry for temporary problems (a 4xx reply, a lost connection); failed when the server
 * refused for good (a 5xx reply). The error is shown to the user.
 */
final class OutboxResultInput extends WorkerRequest
{
    public const string SENT = 'sent';
    public const string RETRY = 'retry';
    public const string FAILED = 'failed';

    #[Assert\Choice(choices: [self::SENT, self::RETRY, self::FAILED])]
    public string $outcome = '';

    #[Assert\Length(max: 500)]
    public ?string $error = null;

    /** False when the message went out but filing the copy in Sent failed. */
    public bool $copiedToSent = true;
}
