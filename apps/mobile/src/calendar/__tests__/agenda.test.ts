import { describe, expect, it } from 'vitest'
import { daysFrom, expandOccurrences } from '@tresso/core'
import { calendarEvent } from '@tresso/core/testing'
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
    expect(groups.map((group) => [group.title, group.subtitle, group.items.map((o) => o.event.id)])).toEqual([
      ['Today', 'Tue 20 Oct', ['meeting']],
      ['Thursday 22 October', '', ['trip']],
      ['Friday 23 October', '', ['trip']],
    ])
  })

  it('shows the week around a day from Monday', () => {
    expect(weekStrip(Date.UTC(2026, 9, 22), AMS).map((day) => new Date(day.wall).getUTCDate())).toEqual([19, 20, 21, 22, 23, 24, 25])
  })
})
