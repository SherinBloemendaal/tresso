import { describe, expect, it } from 'vitest'
import { daysFrom, expandOccurrences, isTodoBlock, todoBlocks } from '@tresso/core'
import { calendarEvent, todo } from '@tresso/core/testing'
import { agendaDays, weekStrip } from '../agenda'

const AMS = 'Europe/Amsterdam'

describe('agenda', () => {
  it('lists the days with something on them, a multi-day event on each of its days', () => {
    const days = daysFrom(Date.UTC(2026, 9, 20), 7, AMS)
    const occurrences = expandOccurrences(
      [
        calendarEvent('meeting', 'cal'),
        calendarEvent('trip', 'cal', { allDay: true, start: { date: '2026-10-22' }, end: { date: '2026-10-24' } }),
      ],
      days[0]!.start,
      days[6]!.end,
      AMS,
    )
    const groups = agendaDays(days, occurrences, Date.UTC(2026, 9, 20, 10))
    expect(groups.map((group) => [group.title, group.subtitle, group.items.map((o) => (isTodoBlock(o) ? o.todo.id : o.event.id))])).toEqual([
      ['Today', 'Tue 20 Oct', ['meeting']],
      ['Thursday 22 October', '', ['trip']],
      ['Friday 23 October', '', ['trip']],
    ])
  })

  it('lists the time planned for todos among the events of its day', () => {
    const days = daysFrom(Date.UTC(2026, 9, 20), 7, AMS)
    const occurrences = expandOccurrences(
      [calendarEvent('trip', 'cal', { allDay: true, start: { date: '2026-10-22' }, end: { date: '2026-10-23' } })],
      days[0]!.start,
      days[6]!.end,
      AMS,
    )
    const planned = todoBlocks(
      [todo('report', { dueDate: '2026-10-23', durationMinutes: 60, plannedStart: '2026-10-22T08:00:00Z', plannedEnd: '2026-10-22T09:00:00Z', planState: 'planned' })],
      days[0]!.start,
      days[6]!.end,
    )
    const groups = agendaDays(days, occurrences, Date.UTC(2026, 9, 20, 10), planned)
    expect(groups.map((group) => [group.title, group.items.map((o) => (isTodoBlock(o) ? `todo ${o.todo.id}` : o.event.id))])).toEqual([['Thursday 22 October', ['trip', 'todo report']]])
  })

  it('shows the week around a day from Monday', () => {
    expect(weekStrip(Date.UTC(2026, 9, 22), AMS).map((day) => new Date(day.wall).getUTCDate())).toEqual([19, 20, 21, 22, 23, 24, 25])
  })
})
