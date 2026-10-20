<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TodoResource;
use App\Entity\Todo;
use App\Repository\TodoRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Only ever returns the signed-in user's own todos.
 *
 * @implements ProviderInterface<TodoResource>
 */
final readonly class TodoProvider implements ProviderInterface
{
    public function __construct(
        private TodoRepository $todos,
        private CurrentUser $currentUser,
    ) {
    }

    /**
     * @return TodoResource|list<TodoResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TodoResource|array
    {
        $owner = $this->currentUser->get();

        if ($operation instanceof CollectionOperationInterface) {
            return array_map(TodoResource::fromEntity(...), $this->todos->findOwnedBy($owner));
        }

        $id = $uriVariables['id'] ?? null;
        $todo = \is_string($id) ? $this->todos->findOneOwnedBy($owner, $id) : null;
        if (!$todo instanceof Todo) {
            throw new NotFoundHttpException('Todo not found.');
        }

        return TodoResource::fromEntity($todo);
    }
}
