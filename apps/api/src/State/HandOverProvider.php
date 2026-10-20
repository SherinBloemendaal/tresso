<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\HandOverResource;
use App\Entity\HandOver;
use App\Repository\HandOverRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Only ever returns the signed-in user's own hand-overs.
 *
 * @implements ProviderInterface<HandOverResource>
 */
final readonly class HandOverProvider implements ProviderInterface
{
    public function __construct(
        private HandOverRepository $handOvers,
        private CurrentUser $currentUser,
    ) {
    }

    /**
     * @return HandOverResource|list<HandOverResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): HandOverResource|array
    {
        $owner = $this->currentUser->get();

        if ($operation instanceof CollectionOperationInterface) {
            return array_map(HandOverResource::fromEntity(...), $this->handOvers->findOwnedBy($owner));
        }

        $id = $uriVariables['id'] ?? null;
        $handOver = \is_string($id) ? $this->handOvers->findOneOwnedBy($owner, $id) : null;
        if (!$handOver instanceof HandOver) {
            throw new NotFoundHttpException('Hand-over not found.');
        }

        return HandOverResource::fromEntity($handOver);
    }
}
