<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync\Calendar;

use App\ApiResource\Input\Sync\WorkerRequest;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Every resource of a calendar with its etag (a full listing). Resources missing from the list are removed, unless
 * the list is partial (one page of a listing too large for one request); the answer names the ones the worker has to
 * fetch.
 */
final class InventoryInput extends WorkerRequest
{
    /** @var list<RemoteObjectInput> */
    #[Assert\Count(max: 50000)]
    #[Assert\Valid]
    public array $objects = [];

    public bool $partial = false;
}
