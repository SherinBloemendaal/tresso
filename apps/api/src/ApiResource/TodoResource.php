<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Todo\CreateTodo;
use App\ApiResource\Input\Todo\MakeTodo;
use App\ApiResource\Input\Todo\UpdateTodo;
use App\Entity\Todo;
use App\Security\Scope;
use App\State\TodoProcessor;
use App\State\TodoProvider;

/**
 * A todo of the signed-in user, synced to every device (the delta carries `todos`). One made from an email links to
 * it (`messageId`, `threadId`); an email has at most one todo, so making one again returns the one it has. Open todos
 * come in the user's order (`position`), done ones after them.
 */
#[ApiResource(
    shortName: 'Todo',
    routePrefix: '/api/v1',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/todos',
            paginationEnabled: false,
            security: "is_granted('".Scope::ROLE_TODOS_READ."')",
            provider: TodoProvider::class,
        ),
        new Get(
            uriTemplate: '/todos/{id}',
            security: "is_granted('".Scope::ROLE_TODOS_READ."')",
            provider: TodoProvider::class,
        ),
        new Post(
            uriTemplate: '/todos',
            security: "is_granted('".Scope::ROLE_TODOS_WRITE."')",
            input: CreateTodo::class,
            read: false,
            processor: TodoProcessor::class,
        ),
        new Post(
            uriTemplate: '/messages/{messageId}/todo',
            uriVariables: ['messageId'],
            status: 200,
            description: 'Makes a todo from an email, or returns the one it has.',
            security: "is_granted('".Scope::ROLE_TODOS_WRITE."')",
            input: MakeTodo::class,
            read: false,
            processor: TodoProcessor::class,
        ),
        new Patch(
            uriTemplate: '/todos/{id}',
            security: "is_granted('".Scope::ROLE_TODOS_WRITE."')",
            input: UpdateTodo::class,
            provider: TodoProvider::class,
            processor: TodoProcessor::class,
        ),
        new Delete(
            uriTemplate: '/todos/{id}',
            security: "is_granted('".Scope::ROLE_TODOS_WRITE."')",
            provider: TodoProvider::class,
            processor: TodoProcessor::class,
        ),
    ],
)]
final readonly class TodoResource
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $title,
        public string $notes,
        /** YYYY-MM-DD in the user's own time zone, or null. */
        public ?string $dueDate,
        /** HH:MM, only with a due date; null means some time that day. */
        public ?string $dueTime,
        public ?int $durationMinutes,
        /** When it was done; null while open. */
        public ?\DateTimeImmutable $completedAt,
        public int $position,
        /** manual, suggested or automatic (made by a smart inbox). */
        public string $source,
        /** The email it came from; null for a todo of the user's own, or once that email or its account is gone. */
        public ?string $messageId,
        public ?string $threadId,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        /**
         * Only when making a todo from an email: whether this request made it (false: the one there was); null
         * otherwise. A client that undoes its own action removes only a todo it made.
         */
        public ?bool $made = null,
    ) {
    }

    public static function fromEntity(Todo $todo, ?bool $made = null): self
    {
        return new self(
            $todo->getId()->toRfc4122(),
            $todo->getTitle(),
            $todo->getNotes(),
            $todo->getDueDate()?->format('Y-m-d'),
            $todo->getDueTime()?->format('H:i'),
            $todo->getDurationMinutes(),
            $todo->getCompletedAt(),
            $todo->getPosition(),
            $todo->getSource()->value,
            $todo->getMessage()?->getId()->toRfc4122(),
            $todo->getMessage()?->getThread()->getId()->toRfc4122(),
            $todo->getCreatedAt(),
            $todo->getUpdatedAt(),
            $made,
        );
    }
}
