<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\SmartInbox\ThreadClassificationInput;
use App\ApiResource\ThreadClassificationResource;
use App\Classification\ThreadCorrection;
use App\Security\CurrentUser;

/**
 * Puts a conversation where the user says it belongs and, unless told not to, remembers that for its sender (see
 * {@see ThreadCorrection}).
 *
 * @implements ProcessorInterface<ThreadClassificationInput, ThreadClassificationResource>
 */
final readonly class ThreadClassificationProcessor implements ProcessorInterface
{
    public function __construct(
        private ThreadCorrection $correction,
        private CurrentUser $currentUser,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ThreadClassificationResource
    {
        $threadId = $uriVariables['threadId'] ?? null;

        return $this->correction->correct($this->currentUser->get(), \is_string($threadId) ? $threadId : '', $data);
    }
}
