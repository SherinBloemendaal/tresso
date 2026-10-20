import { describe, expect, it } from 'vitest'
import { calendarEvent } from '../store/contract'
import { cellOverflow, daysFrom, inAllDayRow, layoutDay, layoutSpans } from './layout'
import { expandOccurrences } from './occurrences'
import type { SyncedCalendarEvent } from './types'

const AMS = 'Europe/Amsterdam'

function timed(id: string, start: string, end: string, extra: Partial<SyncedCalendarEvent> = {}): SyncedCalendarEvent {
  return calendarEvent(id, 'cal', { start: { dateTime: start, timeZone: AMS }, end: { dateTime: end, timeZone: AMS }, ...extra })
}

function occurrences(events: SyncedCalendarEvent[]) {
  return expandOccurrences(events, Date.UTC(2026, 9, 1), Date.UTC(2026, 11, 1), AMS)
}

describe('day layout', () => {
  const [day] = daysFrom(Date.UTC(2026, 9, 20), 1, AMS)

  it('puts blocks at their clock minutes and gives short ones a readable height', () => {
    const blocks = layoutDay(occurrences([timed('a', '2026-10-20T09:00:00', '2026-10-20T10:30:00'), timed('b', '2026-10-20T12:00:00', '2026-10-20T12:05:00')]), day!, AMS)
    expect(blocks.map((b) => [b.occurrence.event.id, b.top, b.height, b.column, b.columns])).toEqual([
      ['a', 540, 90, 0, 1],
      ['b', 720, 15, 0, 1],
    ])
  })

  it('shares the width only among blocks that overlap, as narrow as the group allows', () => {
    const blocks = layoutDay(
      occurrences([
        timed('long', '2026-10-20T09:00:00', '2026-10-20T12:00:00'),
        timed('first', '2026-10-20T09:00:00', '2026-10-20T10:00:00'),
        timed('second', '2026-10-20T10:00:00', '2026-10-20T11:00:00'),
        timed('alone', '2026-10-20T14:00:00', '2026-10-20T15:00:00'),
      ]),
      day!,
      AMS,
    )
    const placed = Object.fromEntries(blocks.map((b) => [b.occurrence.event.id, [b.column, b.columns]]))
    // "second" starts when "first" ends, so it takes the column "first" left free.
    expect(placed).toEqual({ long: [0, 2], first: [1, 2], second: [1, 2], alone: [0, 1] })
  })

  it('cuts an event that crosses midnight into a piece per day', () => {
    const night = occurrences([timed('night', '2026-10-20T22:00:00', '2026-10-21T02:00:00')])
    const [monday, tuesday] = daysFrom(Date.UTC(2026, 9, 20), 2, AMS)
    expect(layoutDay(night, monday!, AMS)[0]).toMatchObject({ top: 1320, height: 120, continuesAfter: true, continuesBefore: false })
    expect(layoutDay(night, tuesday!, AMS)[0]).toMatchObject({ top: 0, height: 120, continuesBefore: true, continuesAfter: false })
  })

  it('places blocks by the clock on the day clocks go back', () => {
    const [sunday] = daysFrom(Date.UTC(2026, 9, 25), 1, AMS)
    expect(sunday!.end - sunday!.start).toBe(25 * 3_600_000)
    const [block] = layoutDay(occurrences([timed('late', '2026-10-25T09:00:00', '2026-10-25T10:00:00')]), sunday!, AMS)
    expect(block).toMatchObject({ top: 540, height: 60 })
  })

  it('sends all-day events and timed events of a day or more to the all-day row', () => {
    const [allDay, long, short] = occurrences([
      calendarEvent('holiday', 'cal', { allDay: true, start: { date: '2026-10-20' }, end: { date: '2026-10-21' } }),
      timed('conference', '2026-10-20T09:00:00', '2026-10-22T17:00:00'),
      timed('meeting', '2026-10-20T13:00:00', '2026-10-20T14:00:00'),
    ])
    expect([allDay, long, short].map((o) => inAllDayRow(o!))).toEqual([true, true, false])
  })
})

describe('span layout', () => {
  const week = daysFrom(Date.UTC(2026, 9, 19), 7, AMS)

  it('packs multi-day items first and stacks what overlaps', () => {
    const items = layoutSpans(
      occurrences([
        calendarEvent('trip', 'cal', { allDay: true, start: { date: '2026-10-21' }, end: { date: '2026-10-24' } }),
        calendarEvent('mon', 'cal', { allDay: true, start: { date: '2026-10-19' }, end: { date: '2026-10-20' } }),
        calendarEvent('wed', 'cal', { allDay: true, start: { date: '2026-10-21' }, end: { date: '2026-10-22' } }),
        calendarEvent('long', 'cal', { allDay: true, start: { date: '2026-10-12' }, end: { date: '2026-10-20' } }),
      ]),
      week,
    )
    expect(items.map((i) => [i.occurrence.event.id, i.first, i.last, i.lane, i.continuesBefore])).toEqual([
      ['long', 0, 0, 0, true],
      ['mon', 0, 0, 1, false],
      ['trip', 2, 4, 0, false],
      ['wed', 2, 2, 1, false],
    ])
  })

  it('decides "+N more" for each cell, not by the lanes of the whole week', () => {
    // "early" holds lane 0 on Monday and Tuesday, so b1 to b4 (Tuesday and Wednesday) take lanes 1 to 4. On Wednesday
    // lane 0 is free: the cell still shows two items and "+2 more", not one item and "+3 more".
    const allDay = (id: string, start: string, end: string) => calendarEvent(id, 'cal', { allDay: true, start: { date: start }, end: { date: end } })
    const items = layoutSpans(
      occurrences([allDay('early', '2026-10-19', '2026-10-21'), ...['b1', 'b2', 'b3', 'b4'].map((id) => allDay(id, '2026-10-20', '2026-10-22'))]),
      week,
    )
    expect(items.find((i) => i.occurrence.event.id === 'b1')?.lane).toBe(1)
    const wednesday = cellOverflow(items, 2, 3)
    expect(wednesday.shown.map((i) => i.occurrence.event.id)).toEqual(['b1', 'b2'])
    expect(wednesday.more).toBe(2)
  })

  it('says how many items a month cell leaves out', () => {
    const many = occurrences([1, 2, 3, 4, 5].map((n) => timed(`m${n}`, `2026-10-20T0${n}:00:00`, `2026-10-20T0${n}:30:00`)))
    const items = layoutSpans(many, week)
    expect(cellOverflow(items, 1, 3)).toMatchObject({ more: 3 })
    expect(cellOverflow(items, 1, 3).shown.map((i) => i.occurrence.event.id)).toEqual(['m1', 'm2'])
    expect(cellOverflow(items, 1, 5).more).toBe(0)
  })
})
