import type { Todo } from '@tresso/core'

/**
 * Check-offs the user just made, by todo id, kept until the copy shows them. A request that went through is followed
 * by a sync, and only the list read after it carries the change; clearing earlier would show the old state for a
 * moment.
 */
export type PendingDone = ReadonlyMap<string, boolean>

/** The pending check-offs the loaded todos do not show yet; a todo that is gone has nothing left to wait for. */
export function stillPending(pending: PendingDone, todos: Todo[]): PendingDone {
  const byId = new Map(todos.map((todo) => [todo.id, todo]))
  const left = new Map<string, boolean>()
  for (const [id, done] of pending) {
    const todo = byId.get(id)
    if (todo !== undefined && (todo.completedAt !== null) !== done) {
      left.set(id, done)
    }
  }
  return left.size === pending.size ? pending : left
}

/** The todos as the user sees them: the pending check-offs on top of the loaded ones. */
export function withPending(todos: Todo[], pending: PendingDone, now: Date): Todo[] {
  return todos.map((todo) => {
    const done = pending.get(todo.id)
    return done === undefined || done === (todo.completedAt !== null) ? todo : { ...todo, completedAt: done ? now.toISOString() : null }
  })
}
