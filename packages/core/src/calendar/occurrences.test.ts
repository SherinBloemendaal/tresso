import { describe, expect, it } from 'vitest'
import { calendarEvent } from '../store/contract'
import { eventSpan, expandOccurrences, expandRange, neverMatches, ruleParts, type Occurrence } from './occurrences'
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

// Each case below is what the server's Occurrences::instances gives for the same iCalendar (run on the API image).
describe('occurrences as the server has them', () => {
  const timed = (start: string, end: string, zone: string | null = AMS) => ({ start: { dateTime: start, timeZone: zone }, end: { dateTime: end, timeZone: zone } })

  it('ends a timed series with an UNTIL date at midnight of that date', () => {
    const series = calendarEvent('a', 'cal', { ...timed('2026-10-05T09:00:00', '2026-10-05T10:00:00'), recurrence: 'FREQ=DAILY;UNTIL=20261008' })
    expect(expandOccurrences([series], from, to, AMS).map((o) => o.recurrenceId)).toEqual(['20261005T070000Z', '20261006T070000Z', '20261007T070000Z'])
  })

  it('keeps the start of a series whose UNTIL comes before it', () => {
    const series = calendarEvent('b', 'cal', { ...timed('2026-10-05T09:00:00', '2026-10-05T10:00:00'), recurrence: 'FREQ=DAILY;UNTIL=20261001T000000Z' })
    expect(expandOccurrences([series], from, to, AMS).map((o) => o.recurrenceId)).toEqual(['20261005T070000Z'])
  })

  it('compares a UTC UNTIL of a floating series as an instant in the reader zone', () => {
    const series = calendarEvent('c', 'cal', { ...timed('2026-10-05T09:00:00', '2026-10-05T10:00:00', null), recurrence: 'FREQ=DAILY;UNTIL=20261007T080000Z' })
    for (const zone of [AMS, 'Asia/Tokyo']) {
      expect(expandOccurrences([series], from, to, zone).map((o) => o.recurrenceId)).toEqual(['20261005T090000Z', '20261006T090000Z', '20261007T090000Z'])
    }
  })

  it('keeps an override whose occurrence an EXDATE also removes', () => {
    const series = calendarEvent('d', 'cal', { uid: 'd@test', ...timed('2026-10-19T09:00:00', '2026-10-19T09:30:00', 'UTC'), recurrence: 'FREQ=DAILY;COUNT=3', exdates: ['20261020T090000Z'] })
    const moved = calendarEvent('d-moved', 'cal', { uid: 'd@test', recurrenceId: '20261020T090000Z', ...timed('2026-10-20T14:00:00', '2026-10-20T14:30:00', 'UTC') })
    expect(expandOccurrences([series, moved], from, to, 'UTC').map((o) => [o.event.id, o.recurrenceId, new Date(o.start).toISOString()])).toEqual([
      ['d', '20261019T090000Z', '2026-10-19T09:00:00.000Z'],
      ['d-moved', '20261020T090000Z', '2026-10-20T14:00:00.000Z'],
      ['d', '20261021T090000Z', '2026-10-21T09:00:00.000Z'],
    ])
  })

  it('moves a weekly 02:30 past the gap on the night clocks go forward', () => {
    const series = calendarEvent('e', 'cal', { ...timed('2026-03-22T02:30:00', '2026-03-22T03:30:00'), recurrence: 'FREQ=WEEKLY;COUNT=3' })
    const occurrences = expandOccurrences([series], Date.UTC(2026, 2, 1), Date.UTC(2026, 4, 1), AMS)
    expect(occurrences.map((o) => o.recurrenceId)).toEqual(['20260322T013000Z', '20260329T013000Z', '20260405T003000Z'])
    expect(occurrences.map((o) => local(o))).toEqual(['2026-03-22T02:30:00', '2026-03-29T03:30:00', '2026-04-05T02:30:00'])
  })

  it('reads a repeated clock time of a daily series as the later one, as the server gives it', () => {
    // Occurrences::instances on the API image: 2026-10-25T02:30:00+01:00 (01:30Z), with and without a jump.
    for (const [first, rule] of [
      ['2026-10-23', 'FREQ=DAILY;COUNT=4'],
      ['2026-10-01', 'FREQ=DAILY'],
    ] as const) {
      const series = calendarEvent('r', 'cal', { ...timed(`${first}T02:30:00`, `${first}T03:00:00`), recurrence: rule })
      const keys = expandOccurrences([series], Date.UTC(2026, 9, 24), Date.UTC(2026, 9, 27), AMS).map((o) => o.recurrenceId)
      expect(keys).toEqual(['20261024T003000Z', '20261025T013000Z', '20261026T013000Z'])
    }
  })

  // The server's output for the same series: 00:00 and 01:00 CEST, then 02:00, 03:00 and 04:00 CET.
  it('steps an hourly series on the clock over the night clocks go back', () => {
    const series = calendarEvent('f', 'cal', { ...timed('2026-10-25T00:00:00', '2026-10-25T00:15:00'), recurrence: 'FREQ=HOURLY;COUNT=5' })
    expect(expandOccurrences([series], Date.UTC(2026, 9, 24), Date.UTC(2026, 9, 26), AMS).map((o) => o.recurrenceId)).toEqual([
      '20261024T220000Z',
      '20261024T230000Z',
      '20261025T010000Z',
      '20261025T020000Z',
      '20261025T030000Z',
    ])
  })

  it('jumps an old hourly series on the clock, as the server does', () => {
    // From summer 1990 every 2 hours at even clock hours. Walking there step by step would pass the step limit, and a
    // jump in elapsed hours would land on the odd hours in winter; the server gives 02:00, 04:00, 06:00 and 08:00.
    const old = calendarEvent('k', 'cal', { ...timed('1990-06-01T00:00:00', '1990-06-01T00:15:00'), recurrence: 'FREQ=HOURLY;INTERVAL=2' })
    const started = Date.now()
    const range = expandRange([old], Date.UTC(2026, 10, 10, 0), Date.UTC(2026, 10, 10, 8), AMS)
    expect(range.truncated).toEqual([])
    expect(range.occurrences.map((o) => o.recurrenceId)).toEqual(['20261110T010000Z', '20261110T030000Z', '20261110T050000Z', '20261110T070000Z'])
    expect(Date.now() - started).toBeLessThan(1000)
  })

  it('applies an override for this and following to every later occurrence, moved as far as it moved', () => {
    const series = calendarEvent('g', 'cal', { uid: 'g@test', summary: 'Sync', ...timed('2026-10-19T09:00:00', '2026-10-19T09:30:00', 'UTC'), recurrence: 'FREQ=WEEKLY;COUNT=4' })
    const later = calendarEvent('g-later', 'cal', { uid: 'g@test', summary: 'Sync, later', recurrenceId: '20261026T090000Z', thisAndFuture: true, ...timed('2026-10-26T11:00:00', '2026-10-26T12:00:00', 'UTC') })
    const occurrences = expandOccurrences([series, later], from, Date.UTC(2026, 11, 1), 'UTC')
    expect(occurrences.map((o) => [o.event.summary, o.recurrenceId, new Date(o.start).toISOString(), new Date(o.end).toISOString(), o.seriesId])).toEqual([
      ['Sync', '20261019T090000Z', '2026-10-19T09:00:00.000Z', '2026-10-19T09:30:00.000Z', 'g'],
      ['Sync, later', '20261026T090000Z', '2026-10-26T11:00:00.000Z', '2026-10-26T12:00:00.000Z', 'g'],
      ['Sync, later', '20261102T090000Z', '2026-11-02T11:00:00.000Z', '2026-11-02T12:00:00.000Z', 'g'],
      ['Sync, later', '20261109T090000Z', '2026-11-09T11:00:00.000Z', '2026-11-09T12:00:00.000Z', 'g'],
    ])
  })

  it('moves later all-day occurrences by the days a range override moved its own', () => {
    const series = calendarEvent('h', 'cal', { uid: 'h@test', allDay: true, start: { date: '2026-10-19' }, end: { date: '2026-10-20' }, recurrence: 'FREQ=WEEKLY;COUNT=4' })
    const later = calendarEvent('h-later', 'cal', { uid: 'h@test', allDay: true, recurrenceId: '20261026', thisAndFuture: true, start: { date: '2026-10-27' }, end: { date: '2026-10-28' } })
    const occurrences = expandOccurrences([series, later], from, Date.UTC(2026, 11, 1), AMS)
    expect(occurrences.map((o) => [o.recurrenceId, o.startDate, o.endDate])).toEqual([
      ['20261019', '2026-10-19', '2026-10-20'],
      ['20261026', '2026-10-27', '2026-10-28'],
      ['20261102', '2026-11-03', '2026-11-04'],
      ['20261109', '2026-11-10', '2026-11-11'],
    ])
  })

  it('gives a rule that can never match only its start, without looping inside ical.js', () => {
    expect(neverMatches(ruleParts('FREQ=DAILY;BYMONTH=2;BYMONTHDAY=30'))).toBe(true)
    expect(neverMatches(ruleParts('FREQ=YEARLY;BYMONTH=2,3;BYMONTHDAY=30'))).toBe(false)
    expect(neverMatches(ruleParts('FREQ=MONTHLY;BYMONTHDAY=-1'))).toBe(false)
    for (const rule of ['FREQ=DAILY;BYMONTH=2;BYMONTHDAY=30', 'FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=30']) {
      const series = calendarEvent('n', 'cal', { ...timed('2026-01-01T09:00:00', '2026-01-01T10:00:00', 'UTC'), recurrence: rule })
      expect(expandOccurrences([series], Date.UTC(2026, 0, 1), Date.UTC(2030, 0, 1), 'UTC').map((o) => o.recurrenceId)).toEqual(['20260101T090000Z'])
    }
  })

  it('leaves out days ical.js normalizes into another month', () => {
    // The 30th of February and of March: only March has one.
    const series = calendarEvent('m', 'cal', { ...timed('2026-01-30T09:00:00', '2026-01-30T10:00:00', 'UTC'), recurrence: 'FREQ=YEARLY;BYMONTH=2,3;BYMONTHDAY=30;COUNT=3' })
    const keys = expandOccurrences([series], Date.UTC(2026, 0, 1), Date.UTC(2029, 0, 1), 'UTC').map((o) => o.recurrenceId)
    expect(keys).toEqual(['20260130T090000Z', '20260330T090000Z', '20270330T090000Z'])
  })

  it('says which series it cut short at the step limit', () => {
    // COUNT cannot be jumped over, so a walk from year 1 hits the limit, as on the server.
    const endless = calendarEvent('x', 'cal', { ...timed('0001-01-01T00:00:00', '0001-01-01T00:30:00', 'UTC'), recurrence: 'FREQ=HOURLY;COUNT=100000000' })
    expect(expandRange([endless], Date.UTC(2026, 9, 19), Date.UTC(2026, 9, 20), 'UTC').truncated).toEqual(['x'])
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
