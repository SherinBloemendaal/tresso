<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TodoResource;
use App\Calendar\TooManyOccurrences;
use App\Repository\TodoRepository;
use App\Security\CurrentUser;
use App\Todo\TodoNotPlannable;
use App\Todo\TodoPlanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Plans one of the user's todos at the earliest free time before it is due, or gives its time back.
 *
 * @implements ProcessorInterface<TodoResource, TodoResource|null>
 */
final readonly class TodoPlanProcessor implements ProcessorInterface
{
    public function __construct(
        private TodoRepository $todos,
        private TodoPlanner $planner,
        private CurrentUser $currentUser,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?TodoResource
    {
        return $this->entityManager->wrapInTransaction(function () use ($operation, $uriVariables): ?TodoResource {
            $id = $uriVariables['id'] ?? null;
            $todo = (\is_string($id) ? $this->todos->findOneOwnedBy($this->currentUser->get(), $id) : null)
                ?? throw new NotFoundHttpException('Todo not found.');

            if ($operation instanceof DeleteOperationInterface) {
                $todo->unplan();
                $this->entityManager->flush();

                return null;
            }

            try {
                $this->planner->plan($todo, $this->clock->now());
            } catch (TodoNotPlannable|TooManyOccurrences $e) {
                throw new UnprocessableEntityHttpException($e->getMessage(), $e);
            }
            $this->entityManager->flush();

            return TodoResource::fromEntity($todo);
        });
    }
}
