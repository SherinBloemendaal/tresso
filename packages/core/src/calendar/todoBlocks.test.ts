import { describe, expect, it } from 'vitest'
import { calendarEvent, todo } from '../store/contract'
import { daysFrom, layoutDay, layoutSpans } from './layout'
import { expandOccurrences } from './occurrences'
import { canPlan, isTodoBlock, plannedLabel, planPath, todoBlocks, todoBlockTimes } from './todoBlocks'

const AMS = 'Europe/Amsterdam'
const planned = { dueDate: '2026-10-24', durationMinutes: 60, plannedStart: '2026-10-20T08:00:00+00:00', plannedEnd: '2026-10-20T09:00:00+00:00', planState: 'planned' as const }

describe('todo blocks', () => {
  it('places the planned time of open todos in the range, earliest first', () => {
    const blocks = todoBlocks(
      [
        todo('later', { ...planned, plannedStart: '2026-10-20T12:00:00+00:00', plannedEnd: '2026-10-20T12:30:00+00:00' }),
        todo('first', planned),
        todo('unplanned', { dueDate: '2026-10-24' }),
        todo('done', { ...planned, completedAt: '2026-10-19T10:00:00+00:00' }),
        todo('outside', { ...planned, plannedStart: '2026-10-22T08:00:00+00:00', plannedEnd: '2026-10-22T09:00:00+00:00' }),
      ],
      Date.UTC(2026, 9, 20),
      Date.UTC(2026, 9, 21),
    )

    expect(blocks.map((block) => [block.key, block.start, block.end, block.allDay, block.conflict])).toEqual([
      ['todo:first', Date.UTC(2026, 9, 20, 8), Date.UTC(2026, 9, 20, 9), false, false],
      ['todo:later', Date.UTC(2026, 9, 20, 12), Date.UTC(2026, 9, 20, 12, 30), false, false],
    ])
  })

  it('marks a conflict', () => {
    const [block] = todoBlocks([todo('a', { ...planned, planState: 'conflict' })], 0, Date.UTC(2027, 0, 1))
    expect(block?.conflict).toBe(true)
  })

  it('shares the time grid with occurrences', () => {
    const [day] = daysFrom(Date.UTC(2026, 9, 20), 1, AMS)
    const meeting = calendarEvent('meeting', 'cal', { start: { dateTime: '2026-10-20T10:30:00', timeZone: AMS }, end: { dateTime: '2026-10-20T11:30:00', timeZone: AMS } })
    const items = [...expandOccurrences([meeting], Date.UTC(2026, 9, 1), Date.UTC(2026, 10, 1), AMS), ...todoBlocks([todo('a', planned)], day!.start, day!.end)]

    const blocks = layoutDay(items, day!, AMS)

    expect(blocks.map((block) => [isTodoBlock(block.occurrence) ? block.occurrence.todo.id : block.occurrence.event.id, block.top, block.height, block.column, block.columns])).toEqual([
      ['a', 600, 60, 0, 2],
      ['meeting', 630, 60, 1, 2],
    ])
    expect(layoutSpans(items, [day!])).toHaveLength(2)
  })

  it('writes the time on the reader’s clock', () => {
    expect(todoBlockTimes({ start: Date.UTC(2026, 9, 20, 8), end: Date.UTC(2026, 9, 20, 9) }, AMS)).toBe('10:00 to 11:00')
    expect(todoBlockTimes({ start: Date.UTC(2026, 9, 20, 21), end: Date.UTC(2026, 9, 20, 23) }, AMS)).toBe('Tue 20 Oct 23:00 to Wed 21 Oct 01:00')
  })

  it('says where a todo is planned, and when that time is in doubt', () => {
    expect(plannedLabel(todo('a', planned), AMS)).toBe('Planned Tuesday 20 October, 10:00 to 11:00')
    expect(plannedLabel(todo('a', { ...planned, planState: 'conflict' }), AMS)).toBe('Planned Tuesday 20 October, 10:00 to 11:00, but that time is taken and nothing else is free before the due date')
    expect(plannedLabel(todo('a'), AMS)).toBeNull()
  })

  it('plans only open todos with a due date and a duration', () => {
    expect(canPlan(todo('a', { dueDate: '2026-10-24', durationMinutes: 30 }))).toBe(true)
    expect(canPlan(todo('a', { dueDate: '2026-10-24' }))).toBe(false)
    expect(canPlan(todo('a', { durationMinutes: 30 }))).toBe(false)
    expect(canPlan(todo('a', { dueDate: '2026-10-24', durationMinutes: 30, completedAt: '2026-10-19T10:00:00+00:00' }))).toBe(false)
    expect(planPath('a b')).toBe('/api/v1/todos/a%20b/plan')
  })
})
