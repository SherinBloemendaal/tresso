import type { Todo } from '../todos'
import { clock, longDate, shortDate } from './format'
import { startOfWallDay, toWall } from './zones'

/**
 * The time a todo is planned at, as the calendar views place it next to occurrences (see `Placed` in layout). It is in
 * no calendar: Tresso keeps it, and it counts as busy for the meeting time finder.
 */
export interface TodoBlock {
  /** Unique among the occurrences too: `todo:` and the todo's id. */
  key: string
  todo: Todo
  allDay: false
  /** Instants (ms). */
  start: number
  end: number
  /** The time is no longer free and no other free time was found before the due date. */
  conflict: boolean
}

export function isTodoBlock(item: object): item is TodoBlock {
  return 'todo' in item
}

/** The planned time of open todos that overlaps [from, to), earliest first. */
export function todoBlocks(todos: Todo[], from: number, to: number): TodoBlock[] {
  const blocks: TodoBlock[] = []
  for (const todo of todos) {
    if (todo.completedAt !== null || todo.plannedStart === null || todo.plannedEnd === null) {
      continue
    }
    const start = Date.parse(todo.plannedStart)
    const end = Date.parse(todo.plannedEnd)
    if (start < to && end > from) {
      blocks.push({ key: `todo:${todo.id}`, todo, allDay: false, start, end, conflict: todo.planState === 'conflict' })
    }
  }
  return blocks.sort((a, b) => a.start - b.start || a.key.localeCompare(b.key))
}

/** "10:00 to 10:30" in the reader's zone; with the days when it runs past midnight. */
export function todoBlockTimes(block: Pick<TodoBlock, 'start' | 'end'>, zone: string): string {
  const start = toWall(block.start, zone)
  const end = toWall(block.end, zone)
  if (startOfWallDay(start) === startOfWallDay(end) || end === startOfWallDay(end)) {
    return `${clock(start)} to ${clock(end)}`
  }
  return `${shortDate(start)} ${clock(start)} to ${shortDate(end)} ${clock(end)}`
}

/**
 * Where a todo is planned, in words: "Planned Monday 22 March, 09:00 to 10:00", or why that time is in doubt. Null while
 * it is not planned.
 */
export function plannedLabel(todo: Pick<Todo, 'plannedStart' | 'plannedEnd' | 'planState'>, zone: string, today?: number): string | null {
  if (todo.plannedStart === null || todo.plannedEnd === null) {
    return null
  }
  const block = { start: Date.parse(todo.plannedStart), end: Date.parse(todo.plannedEnd) }
  const when = `${longDate(toWall(block.start, zone), today)}, ${todoBlockTimes(block, zone)}`
  return todo.planState === 'conflict' ? `Planned ${when}, but that time is taken and nothing else is free before the due date` : `Planned ${when}`
}

/** Whether "Plan automatically" can work for a todo: it is open, with a due date and a duration. */
export function canPlan(todo: Pick<Todo, 'completedAt' | 'dueDate' | 'durationMinutes'>): boolean {
  return todo.completedAt === null && todo.dueDate !== null && todo.durationMinutes !== null
}

/** POST plans (or plans again) at the earliest free time before the due date; DELETE gives the time back. */
export function planPath(todoId: string): string {
  return `/api/v1/todos/${encodeURIComponent(todoId)}/plan`
}
