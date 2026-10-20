import { describe, expect, it } from 'vitest'
import { calendarEvent } from '../store/contract'
import { eventSpan, expandOccurrences, type Occurrence } from './occurrences'
import type { SyncedCalendarEvent } from './types'
import { formatDateTime, toWall } from './zones'

const AMS = 'Europe/Amsterdam'
const from = Date.UTC(2026, 9, 1)
const to = Date.UTC(2026, 10, 1)

function local(occurrence: Occurrence, zone = AMS): string {
  return formatDateTime(toWall(occurrence.start, zone))
}

function weekly(extra: Partial<SyncedCalendarEvent> = {}): SyncedCalendarEvent {
  return calendarEvent('series', 'cal', {
    uid: 'series@tresso.test',
    start: { dateTime: '2026-10-05T09:00:00', timeZone: AMS },
    end: { dateTime: '2026-10-05T10:00:00', timeZone: AMS },
    recurrence: 'FREQ=WEEKLY;BYDAY=MO',
    ...extra,
  })
}

describe('occurrences', () => {
  it('shows a single event once, with its own times', () => {
    const [only, ...rest] = expandOccurrences([calendarEvent('e', 'cal')], from, to, AMS)
    expect(rest).toHaveLength(0)
    expect(only).toMatchObject({ recurring: false, recurrenceId: null, seriesId: 'e', start: Date.UTC(2026, 9, 20, 8), end: Date.UTC(2026, 9, 20, 9) })
  })

  it('keeps the clock time of a series across the change to winter time', () => {
    const occurrences = expandOccurrences([weekly()], from, to, AMS)
    expect(occurrences.map((o) => local(o))).toEqual(['2026-10-05T09:00:00', '2026-10-12T09:00:00', '2026-10-19T09:00:00', '2026-10-26T09:00:00'])
    // Recurrence ids are UTC instants, so the one after the change is an hour later in UTC.
    expect(occurrences.map((o) => o.recurrenceId)).toEqual(['20261005T070000Z', '20261012T070000Z', '20261019T070000Z', '20261026T080000Z'])
    expect(occurrences.map((o) => o.first)).toEqual([true, false, false, false])
  })

  it('shows the series in the reader zone without moving it', () => {
    const occurrences = expandOccurrences([weekly()], from, to, 'America/New_York')
    // New York changes to winter time a week later, so for one week the meeting is an hour earlier there.
    expect(occurrences.map((o) => local(o, 'America/New_York'))).toEqual(['2026-10-05T03:00:00', '2026-10-12T03:00:00', '2026-10-19T03:00:00', '2026-10-26T04:00:00'])
  })

  it('leaves out excluded dates and applies overrides, also ones moved out of the range', () => {
    const override = calendarEvent('moved', 'cal', {
      uid: 'series@tresso.test',
      recurrenceId: '20261012T070000Z',
      summary: 'Moved review',
      start: { dateTime: '2026-10-13T14:00:00', timeZone: AMS },
      end: { dateTime: '2026-10-13T15:00:00', timeZone: AMS },
    })
    const gone = calendarEvent('gone', 'cal', {
      uid: 'series@tresso.test',
      recurrenceId: '20261026T080000Z',
      start: { dateTime: '2026-11-20T09:00:00', timeZone: AMS },
      end: { dateTime: '2026-11-20T10:00:00', timeZone: AMS },
    })
    const occurrences = expandOccurrences([weekly({ exdates: ['20261019T070000Z'] }), override, gone], from, to, AMS)
    expect(occurrences.map((o) => [local(o), o.event.id, o.recurrenceId, o.seriesId])).toEqual([
      ['2026-10-05T09:00:00', 'series', '20261005T070000Z', 'series'],
      ['2026-10-13T14:00:00', 'moved', '20261012T070000Z', 'series'],
    ])
  })

  it('counts the start as the first occurrence and stops at COUNT and UNTIL', () => {
    const counted = expandOccurrences([weekly({ recurrence: 'FREQ=WEEKLY;BYDAY=MO;COUNT=2' })], from, to, AMS)
    expect(counted.map((o) => local(o))).toEqual(['2026-10-05T09:00:00', '2026-10-12T09:00:00'])
    // The until is inclusive: 07:00 UTC is the 09:00 occurrence of 19 October.
    const until = expandOccurrences([weekly({ recurrence: 'FREQ=WEEKLY;BYDAY=MO;UNTIL=20261019T070000Z' })], from, to, AMS)
    expect(until.map((o) => local(o))).toEqual(['2026-10-05T09:00:00', '2026-10-12T09:00:00', '2026-10-19T09:00:00'])
    // A start the rule would not give (a Sunday for a Monday rule) still counts, and takes one of the COUNT.
    const offRule = expandOccurrences([weekly({ start: { dateTime: '2026-10-04T09:00:00', timeZone: AMS }, end: { dateTime: '2026-10-04T10:00:00', timeZone: AMS }, recurrence: 'FREQ=WEEKLY;BYDAY=MO;COUNT=2' })], from, to, AMS)
    expect(offRule.map((o) => local(o))).toEqual(['2026-10-04T09:00:00', '2026-10-05T09:00:00'])
  })

  it('adds extra dates to the rule, each in order', () => {
    const occurrences = expandOccurrences([weekly({ recurrence: 'FREQ=WEEKLY;BYDAY=MO;COUNT=2', rdates: ['20261021T120000Z'] })], from, to, AMS)
    expect(occurrences.map((o) => local(o))).toEqual(['2026-10-05T09:00:00', '2026-10-12T09:00:00', '2026-10-21T14:00:00'])
  })

  it('keeps all-day series on their dates in every zone, as many days as the event lasts', () => {
    const holiday = calendarEvent('trip', 'cal', { allDay: true, start: { date: '2026-10-24' }, end: { date: '2026-10-26' }, recurrence: 'FREQ=YEARLY' })
    for (const zone of [AMS, 'America/Los_Angeles', 'Pacific/Auckland']) {
      const [trip] = expandOccurrences([holiday], from, to, zone)
      expect(trip).toMatchObject({ allDay: true, startDate: '2026-10-24', endDate: '2026-10-26', recurrenceId: '20261024' })
      expect(formatDateTime(toWall(trip!.start, zone))).toBe('2026-10-24T00:00:00')
      expect(formatDateTime(toWall(trip!.end, zone))).toBe('2026-10-26T00:00:00')
    }
    // Over the change to winter time the two days are 49 hours long in Amsterdam.
    const [trip] = expandOccurrences([holiday], from, to, AMS)
    expect(trip!.end - trip!.start).toBe(49 * 3_600_000)
  })

  it('reads floating events at the same clock time everywhere, with UTC-read recurrence ids', () => {
    const floating = weekly({ start: { dateTime: '2026-10-05T07:30:00', timeZone: null }, end: { dateTime: '2026-10-05T08:00:00', timeZone: null }, recurrence: 'FREQ=DAILY;COUNT=1' })
    const [ams] = expandOccurrences([floating], from, to, AMS)
    const [tokyo] = expandOccurrences([floating], from, to, 'Asia/Tokyo')
    expect(local(ams!)).toBe('2026-10-05T07:30:00')
    expect(local(tokyo!, 'Asia/Tokyo')).toBe('2026-10-05T07:30:00')
    expect(ams!.recurrenceId).toBe('20261005T073000Z')
  })

  it('shows an occurrence the user was invited to without its series as an event of its own', () => {
    const lone = calendarEvent('lone', 'cal', { uid: 'other@tresso.test', recurrenceId: '20261020T080000Z' })
    const [occurrence] = expandOccurrences([lone], from, to, AMS)
    expect(occurrence).toMatchObject({ recurring: false, recurrenceId: '20261020T080000Z', seriesId: 'lone' })
  })

  it('only returns occurrences that touch the range', () => {
    const occurrences = expandOccurrences([weekly()], Date.UTC(2026, 9, 12, 7, 30), Date.UTC(2026, 9, 19, 7), AMS)
    expect(occurrences.map((o) => local(o))).toEqual(['2026-10-12T09:00:00'])
  })

  it('walks an old daily series to a range years later', () => {
    const old = weekly({ start: { dateTime: '2016-01-01T09:00:00', timeZone: AMS }, end: { dateTime: '2016-01-01T09:15:00', timeZone: AMS }, recurrence: 'FREQ=DAILY' })
    expect(expandOccurrences([old], from, to, AMS)).toHaveLength(31)
  })
})

describe('event spans', () => {
  it('has no end for a series that never ends or ends by count', () => {
    expect(eventSpan(weekly()).endsAt).toBeNull()
    expect(eventSpan(weekly({ recurrence: 'FREQ=DAILY;COUNT=3' })).endsAt).toBeNull()
  })

  it('ends after the last occurrence of a series with an until', () => {
    const span = eventSpan(weekly({ recurrence: 'FREQ=WEEKLY;UNTIL=20261019T070000Z' }))
    expect(span.endsAt).toBeGreaterThanOrEqual(Date.UTC(2026, 9, 19, 8))
  })

  it('widens all-day events by the zones they can be read in', () => {
    const span = eventSpan(calendarEvent('d', 'cal', { allDay: true, start: { date: '2026-10-21' }, end: { date: '2026-10-22' } }))
    expect(span.startsAt).toBeLessThanOrEqual(Date.UTC(2026, 9, 20, 10))
    expect(span.endsAt).toBeGreaterThanOrEqual(Date.UTC(2026, 9, 22, 14))
  })
})
