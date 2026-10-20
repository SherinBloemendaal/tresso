<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\BulkActionResource;
use App\Repository\BulkActionRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Only ever returns the signed-in user's own bulk actions.
 *
 * @implements ProviderInterface<BulkActionResource>
 */
final readonly class BulkActionProvider implements ProviderInterface
{
    public function __construct(
        private BulkActionRepository $bulkActions,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BulkActionResource
    {
        $id = $uriVariables['id'] ?? null;
        $bulkAction = \is_string($id) ? $this->bulkActions->findOneOwnedBy($this->currentUser->get(), $id) : null;
        if (null === $bulkAction) {
            throw new NotFoundHttpException('Bulk action not found.');
        }

        return BulkActionResource::fromEntity($bulkAction);
    }
}
