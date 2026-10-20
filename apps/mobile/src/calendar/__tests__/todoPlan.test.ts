import { describe, expect, it } from 'vitest'
import { todo } from '@tresso/core/testing'
import { todoPlanChoices } from '../todoPlan'

const plannable = { dueDate: '2026-10-23', durationMinutes: 60 }

describe('todo plan choices', () => {
  it('offers to plan a todo that can be, and to plan again or remove time it has', () => {
    expect(todoPlanChoices(todo('a', plannable)).map((choice) => choice.label)).toEqual(['Plan automatically'])
    expect(todoPlanChoices(todo('a', { ...plannable, plannedStart: '2026-10-21T08:00:00Z', plannedEnd: '2026-10-21T09:00:00Z', planState: 'planned' }), { withDone: true })).toEqual([
      { action: 'plan', label: 'Plan again' },
      { action: 'unplan', label: 'Remove from calendar' },
      { action: 'done', label: 'Done' },
    ])
  })

  it('offers nothing for a todo without a due date or a duration, or one that is done', () => {
    expect(todoPlanChoices(todo('a', { dueDate: '2026-10-23' }))).toEqual([])
    expect(todoPlanChoices(todo('a', { durationMinutes: 30 }))).toEqual([])
    expect(todoPlanChoices(todo('a', { ...plannable, completedAt: '2026-10-20T08:00:00Z' }))).toEqual([])
  })
})
