<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\SyncLease;
use App\Sync\AccountLeaser;
use App\Sync\ReleaseResult;

/**
 * @implements ProcessorInterface<SyncLease, ReleaseResult>
 */
final readonly class SyncReleaseProcessor implements ProcessorInterface
{
    public function __construct(private AccountLeaser $leaser)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ReleaseResult
    {
        return new ReleaseResult($this->leaser->release($data->workerId));
    }
}
