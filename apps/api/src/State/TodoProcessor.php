<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\Todo\CreateTodo;
use App\ApiResource\Input\Todo\MakeTodo;
use App\ApiResource\Input\Todo\UpdateTodo;
use App\ApiResource\TodoResource;
use App\Entity\Todo;
use App\Entity\User;
use App\Enum\TodoSource;
use App\Repository\MessageRepository;
use App\Repository\TodoRepository;
use App\Security\CurrentUser;
use App\Todo\EmailTodos;
use App\Todo\TodoPlanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Creates, updates and deletes the signed-in user's todos, and makes todos from their emails. The time a todo holds in
 * the calendar follows its changes ({@see TodoPlanner::follow()}).
 *
 * @implements ProcessorInterface<CreateTodo|MakeTodo|UpdateTodo|TodoResource, TodoResource|null>
 */
final readonly class TodoProcessor implements ProcessorInterface
{
    public function __construct(
        private TodoRepository $todos,
        private MessageRepository $messages,
        private EmailTodos $emailTodos,
        private CurrentUser $currentUser,
        private EntityManagerInterface $entityManager,
        private TodoPlanner $planner,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?TodoResource
    {
        // One transaction per write, so change numbers for client sync commit in order.
        return $this->entityManager->wrapInTransaction(fn (): ?TodoResource => $this->write($data, $operation, $uriVariables));
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function write(mixed $data, Operation $operation, array $uriVariables): ?TodoResource
    {
        $owner = $this->currentUser->get();

        if ($data instanceof CreateTodo) {
            $todo = new Todo($owner, trim($data->title), TodoSource::Manual);
            $todo->setNotes($data->notes);
            $todo->setDue(self::date($data->dueDate), self::time($data->dueTime));
            $todo->setDurationMinutes($data->durationMinutes);
            $todo->setPosition($this->todos->nextPosition($owner));
            $this->entityManager->persist($todo);
            $this->entityManager->flush();
            $this->planner->follow($todo, true, $this->clock->now());
            $this->entityManager->flush();

            return TodoResource::fromEntity($todo);
        }

        if ($data instanceof MakeTodo) {
            return $this->makeFromEmail($owner, $data, $uriVariables);
        }

        $id = $uriVariables['id'] ?? null;
        $todo = (\is_string($id) ? $this->todos->findOneOwnedBy($owner, $id) : null)
            ?? throw new NotFoundHttpException('Todo not found.');

        if ($operation instanceof DeleteOperationInterface) {
            // An automatic todo the user deletes is one they do not want: none is made for that email again.
            if (TodoSource::Automatic === $todo->getSource()) {
                $todo->getMessage()?->declineTodo(new \DateTimeImmutable());
            }
            $this->entityManager->remove($todo);
            $this->entityManager->flush();

            return null;
        }

        if (!$data instanceof UpdateTodo) {
            throw new \LogicException(\sprintf('Unexpected input %s for %s.', get_debug_type($data), $operation->getName()));
        }

        $schedule = [$todo->getDueDate(), $todo->getDueTime(), $todo->getDurationMinutes(), $todo->isDone()];
        $this->update($todo, $data);
        $this->entityManager->flush();
        // Equal by value: a date set again to the same day is no change.
        $this->planner->follow($todo, $schedule != [$todo->getDueDate(), $todo->getDueTime(), $todo->getDurationMinutes(), $todo->isDone()], $this->clock->now());
        $this->entityManager->flush();

        return TodoResource::fromEntity($todo);
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function makeFromEmail(User $owner, MakeTodo $data, array $uriVariables): TodoResource
    {
        $messageId = $uriVariables['messageId'] ?? null;
        $message = (\is_string($messageId) ? $this->messages->findOneOwnedBy($owner, $messageId) : null)
            ?? throw new NotFoundHttpException('Message not found.');

        [$todo, $made] = $this->emailTodos->make($owner, $message, null === $data->title ? null : trim($data->title), TodoSource::Manual, $data->perConversation);
        if ($made) {
            $todo->setNotes($data->notes);
            $todo->setDue(self::date($data->dueDate), self::time($data->dueTime));
            $todo->setDurationMinutes($data->durationMinutes);
            $this->entityManager->flush();
            $this->planner->follow($todo, true, $this->clock->now());
            $this->entityManager->flush();
        }

        return TodoResource::fromEntity($todo, $made);
    }

    private function update(Todo $todo, UpdateTodo $data): void
    {
        if ($data->has('title')) {
            $todo->setTitle(trim($data->title));
        }
        if ($data->has('notes')) {
            $todo->setNotes($data->notes);
        }
        if ($data->has('dueDate') || $data->has('dueTime')) {
            $date = $data->has('dueDate') ? self::date($data->dueDate) : $todo->getDueDate();
            if (null === $date && $data->has('dueTime') && null !== $data->dueTime) {
                throw self::invalid($data, 'dueTime', 'A due time needs a due date.', $data->dueTime);
            }
            // A cleared date takes its time with it; a new date keeps the time unless the request sets one.
            $time = match (true) {
                null === $date => null,
                $data->has('dueTime') => self::time($data->dueTime),
                default => $todo->getDueTime(),
            };
            $todo->setDue($date, $time);
        }
        if ($data->has('durationMinutes')) {
            $todo->setDurationMinutes($data->durationMinutes);
        }
        if ($data->has('done') && $data->done) {
            $todo->complete(new \DateTimeImmutable());
        }
        if ($data->has('done') && !$data->done) {
            $todo->reopen();
        }
        if ($data->has('position')) {
            $todo->setPosition($data->position);
        }
    }

    private static function date(?string $value): ?\DateTimeImmutable
    {
        return null === $value ? null : (\DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: throw new \LogicException('The date was validated.'));
    }

    private static function time(?string $value): ?\DateTimeImmutable
    {
        return null === $value ? null : (\DateTimeImmutable::createFromFormat('!H:i', $value) ?: throw new \LogicException('The time was validated.'));
    }

    private static function invalid(mixed $input, string $path, string $message, mixed $value): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([new ConstraintViolation($message, null, [], $input, $path, $value)]));
    }
}
