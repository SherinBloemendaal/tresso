<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\SmartInboxResource;
use App\Entity\Category;
use App\Repository\CategoryRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Only ever returns the signed-in user's own smart inboxes, in sidebar order.
 *
 * @implements ProviderInterface<SmartInboxResource>
 */
final readonly class SmartInboxProvider implements ProviderInterface
{
    public function __construct(
        private CategoryRepository $smartInboxes,
        private CurrentUser $currentUser,
    ) {
    }

    /**
     * @return SmartInboxResource|list<SmartInboxResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SmartInboxResource|array
    {
        $owner = $this->currentUser->get();

        if ($operation instanceof CollectionOperationInterface) {
            return array_map(SmartInboxResource::fromEntity(...), $this->smartInboxes->findOwnedBy($owner));
        }

        $id = $uriVariables['id'] ?? null;
        $smartInbox = \is_string($id) ? $this->smartInboxes->findOneOwnedBy($owner, $id) : null;
        if (!$smartInbox instanceof Category) {
            throw new NotFoundHttpException('Smart inbox not found.');
        }

        return SmartInboxResource::fromEntity($smartInbox);
    }
}
