import { canPlan, type Todo } from '@tresso/core'

export type TodoPlanAction = 'plan' | 'unplan' | 'done'

/** What can be done with a todo's time in the calendar, in the order the menu offers it. */
export function todoPlanChoices(todo: Todo, options: { withDone?: boolean } = {}): { action: TodoPlanAction; label: string }[] {
  if (!canPlan(todo)) {
    return []
  }
  const choices: { action: TodoPlanAction; label: string }[] = [{ action: 'plan', label: todo.plannedStart === null ? 'Plan automatically' : 'Plan again' }]
  if (todo.plannedStart !== null) {
    choices.push({ action: 'unplan', label: 'Remove from calendar' })
  }
  if (options.withDone === true) {
    choices.push({ action: 'done', label: 'Done' })
  }
  return choices
}
