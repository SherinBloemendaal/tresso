<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\ThreadClassificationResource;
use App\Classification\ThreadCorrection;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Takes back the user's placement of a conversation, so the rules place it again (see {@see ThreadCorrection::release()}).
 * The query names the placement being taken back (`placementId`, from the PUT), so a later one is never undone with it.
 *
 * @implements ProcessorInterface<ThreadClassificationResource, null>
 */
final readonly class ThreadClassificationReleaseProcessor implements ProcessorInterface
{
    public function __construct(
        private ThreadCorrection $correction,
        private CurrentUser $currentUser,
        private RequestStack $requests,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $threadId = $uriVariables['threadId'] ?? null;
        $query = $this->requests->getCurrentRequest()?->query;
        $placementId = $query?->getString('placementId') ?? '';
        if (!Uuid::isValid($placementId)) {
            throw new BadRequestHttpException('Name the placement to take back: placementId.');
        }
        $this->correction->release($this->currentUser->get(), \is_string($threadId) ? $threadId : '', Uuid::fromString($placementId)->toRfc4122());

        return null;
    }
}
