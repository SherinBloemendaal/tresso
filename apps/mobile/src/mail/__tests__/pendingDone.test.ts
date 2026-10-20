import { describe, expect, it } from 'vitest'
import type { Todo } from '@tresso/core'
import { stillPending, withPending } from '../pendingDone'

function todo(id: string, completedAt: string | null = null): Todo {
  return { id, title: id, notes: '', dueDate: null, dueTime: null, durationMinutes: null, completedAt, position: 0, source: 'manual', messageId: null, threadId: null, createdAt: '2026-10-20T08:00:00Z', updatedAt: '2026-10-20T08:00:00Z' }
}

describe('pending check-offs', () => {
  const now = new Date('2026-10-20T10:00:00Z')

  it('shows a check-off before the copy has it', () => {
    expect(withPending([todo('a'), todo('b')], new Map([['a', true]]), now).map((t) => t.completedAt)).toEqual(['2026-10-20T10:00:00.000Z', null])
  })

  it('keeps a check-off until the loaded list shows it, so it never flips back for a moment', () => {
    const pending = new Map([['a', true]])

    expect(stillPending(pending, [todo('a')])).toBe(pending)
    expect(stillPending(pending, [todo('a', '2026-10-20T09:59:00Z')]).size).toBe(0)
  })

  it('lets go of a check-off whose todo is gone', () => {
    expect(stillPending(new Map([['a', false]]), [todo('b')]).size).toBe(0)
  })
})
