<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync;

use App\Sync\LeaseGuard;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Every ingest call names the worker so the lease can be checked.
 */
abstract class WorkerRequest
{
    #[Assert\NotBlank]
    #[Assert\Regex(LeaseGuard::WORKER_ID_PATTERN)]
    public string $workerId = '';
}
