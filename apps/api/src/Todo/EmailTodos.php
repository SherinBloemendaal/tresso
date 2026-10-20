<?php

declare(strict_types=1);

namespace App\Todo;

use App\Classification\UserLocks;
use App\Entity\Message;
use App\Entity\Todo;
use App\Entity\User;
use App\Enum\TodoSource;
use App\Enum\Verdict;
use App\Repository\TodoRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Makes todos from emails, by hand or automatically, and keeps it at one todo per email: every path takes the user's
 * todos lock (see {@see UserLocks}) before it looks, and the database refuses a second one anyway.
 */
final readonly class EmailTodos
{
    /**
     * How recent new mail must be to become a todo automatically. A first sync brings years of old mail, which should
     * not flood the list.
     */
    public const string AUTOMATIC_WINDOW = '-7 days';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TodoRepository $todos,
        private UserLocks $locks,
    ) {
    }

    /**
     * The email's todo: the one it has, or a new one with this title (the subject when null). Runs inside the caller's
     * transaction; the new todo is persisted, not flushed.
     *
     * @return array{Todo, bool} the todo, and whether it was made now
     */
    public function make(User $owner, Message $message, ?string $title, TodoSource $source): array
    {
        $this->locks->todos($owner->getId());
        $existing = $this->todos->findOneByMessage($message);
        if (null !== $existing) {
            return [$existing, false];
        }

        $todo = new Todo($owner, $title ?? self::titleOf($message), $source, $message);
        $todo->setPosition($this->todos->nextPosition($owner));
        $this->entityManager->persist($todo);

        return [$todo, true];
    }

    /**
     * Makes todos for new mail that was just classified into a smart inbox that asks for them: unread, recent (see
     * {@see AUTOMATIC_WINDOW}), judged `do`, and without a todo yet. A conversation gets one open todo: a reply in a
     * conversation that has one already (or a second email of it in the same batch) makes none. Runs inside the
     * classification's transaction, after its lock; the new todos are persisted, not flushed.
     *
     * @param list<Message> $messages
     */
    public function makeAutomatically(User $owner, array $messages, \DateTimeImmutable $now): void
    {
        $since = $now->modify(self::AUTOMATIC_WINDOW);
        $wanted = array_values(array_filter($messages, static fn (Message $message): bool => true === $message->getCategory()?->isAutoCreateTodo()
            && Verdict::Do === $message->getVerdict()
            && !$message->isSeen()
            && $message->getReceivedAt() >= $since));
        if ([] === $wanted) {
            return;
        }

        $this->locks->todos($owner->getId());
        $taken = $this->todos->messagesWithTodo(array_map(static fn (Message $message): string => $message->getId()->toRfc4122(), $wanted));
        $busyThreads = $this->todos->threadsWithOpenTodo(array_values(array_unique(array_map(static fn (Message $message): string => $message->getThread()->getId()->toRfc4122(), $wanted))));
        $position = $this->todos->nextPosition($owner);
        foreach ($wanted as $message) {
            $threadId = $message->getThread()->getId()->toRfc4122();
            if (isset($taken[$message->getId()->toRfc4122()]) || isset($busyThreads[$threadId])) {
                continue;
            }
            $busyThreads[$threadId] = true;
            $todo = new Todo($owner, self::titleOf($message), TodoSource::Automatic, $message);
            $todo->setPosition($position++);
            $this->entityManager->persist($todo);
        }
    }

    /** The subject, or who sent it when there is none. */
    private static function titleOf(Message $message): string
    {
        $subject = trim($message->getSubject());
        if ('' !== $subject) {
            return mb_substr($subject, 0, Todo::TITLE_MAX_LENGTH);
        }

        return mb_substr('Email from '.($message->getFromName() ?? $message->getFromAddress()), 0, Todo::TITLE_MAX_LENGTH);
    }
}
