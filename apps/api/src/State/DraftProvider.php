<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\DraftResource;
use App\Entity\Draft;
use App\Repository\DraftRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<DraftResource>
 */
final readonly class DraftProvider implements ProviderInterface
{
    public function __construct(
        private DraftRepository $drafts,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DraftResource
    {
        $id = $uriVariables['id'] ?? null;
        $draft = \is_string($id) ? $this->drafts->findOneOwnedBy($this->currentUser->get(), $id) : null;
        if (!$draft instanceof Draft) {
            throw new NotFoundHttpException('Draft not found.');
        }

        return DraftResource::fromEntity($draft);
    }
}
