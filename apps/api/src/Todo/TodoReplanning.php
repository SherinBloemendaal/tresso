<?php

declare(strict_types=1);

namespace App\Todo;

use App\Entity\User;
use App\Realtime\AfterCommitDispatcher;

/**
 * Asks for the user's todos to be checked again once the current transaction commits: something their planned time
 * depends on changed (calendar events, which calendars count, the zone, working hours, scheduling preferences). One
 * check per user and commit, however many changes it holds.
 */
final readonly class TodoReplanning
{
    public function __construct(private AfterCommitDispatcher $afterCommit)
    {
    }

    public function afterCommit(User|string $user): void
    {
        $id = $user instanceof User ? $user->getId()->toRfc4122() : $user;
        $this->afterCommit->dispatchOnce('replan-todos:'.$id, new ReplanTodos($id));
    }
}
