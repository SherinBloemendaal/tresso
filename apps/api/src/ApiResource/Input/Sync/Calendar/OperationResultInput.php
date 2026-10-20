<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync\Calendar;

use App\ApiResource\Input\Sync\WorkerRequest;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * How a calendar write went: done (with the new remote id and etag), conflict (the provider's version changed; the
 * worker sends that version, or none when the resource is gone), failed for good (refused), or retry later.
 */
final class OperationResultInput extends WorkerRequest
{
    public const string DONE = 'done';
    public const string CONFLICT = 'conflict';
    public const string FAILED = 'failed';
    public const string RETRY = 'retry';

    #[Assert\Choice(choices: [self::DONE, self::CONFLICT, self::FAILED, self::RETRY])]
    public string $outcome = '';

    #[Assert\Length(max: 2048)]
    public ?string $remoteId = null;

    #[Assert\Length(max: 512)]
    public ?string $etag = null;

    #[Assert\Length(max: 500)]
    public ?string $error = null;

    /**
     * The provider's current version after a conflict or refusal, or after a write the provider changed (a Google
     * Meet or Teams meeting it added).
     */
    #[Assert\Valid]
    public ?RemoteObjectInput $current = null;
}
