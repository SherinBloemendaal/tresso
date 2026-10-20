import ICAL from 'ical.js'
import type { EventTime, SyncedCalendarEvent } from './types'
import { DAY, isTimeZone, parseRecurrenceKey, parseWall, recurrenceKey, toInstant, toWall, wallOf, wallParts } from './zones'

/**
 * One occurrence of an event, as the views show it: recurrence expanded, overrides applied and excluded dates left
 * out, the way GET /api/v1/calendar-occurrences answers (the reference this is tested against).
 */
export interface Occurrence {
  /** Unique among the occurrences: the event id and the recurrence id. */
  key: string
  /** The row the occurrence takes its fields from: the override of this occurrence, else the series or the event. */
  event: SyncedCalendarEvent
  /** The series it belongs to; the row edits and RSVPs go to, with {@link recurrenceId}. */
  seriesId: string
  /** The series row, for its rule; null for an event that does not repeat. */
  series: SyncedCalendarEvent | null
  /** The occurrence's original start (YYYYMMDD or YYYYMMDDTHHMMSSZ); null for an event that does not repeat. */
  recurrenceId: string | null
  recurring: boolean
  /** The first occurrence of its series: "this and following" is all of it. */
  first: boolean
  allDay: boolean
  /** Instants (ms). All-day occurrences start and end at midnight in the reader's zone. */
  start: number
  end: number
  /** For all-day occurrences: the first day and the day after the last (YYYY-MM-DD); null otherwise. */
  startDate: string | null
  endDate: string | null
  /** The zone the event's own times are in; null for floating and all-day events. */
  timeZone: string | null
}

/** How far one series is walked before it stops, as on the server: an old dense series costs no more than a new one. */
const MAX_STEPS = 100_000

/** A zone the platform knows: the event's own, else the reader's. */
function zoneOr(zone: string | null, readerZone: string): string {
  return zone !== null && isTimeZone(zone) ? zone : readerZone
}

function wallOfTime(time: EventTime): number {
  const wall = parseWall('date' in time ? time.date : time.dateTime)
  if (wall === null) {
    throw new Error(`Not a calendar time: ${JSON.stringify(time)}`)
  }
  return wall
}

/** The instant an event time stands for; dates and floating times are read in the reader's zone. */
export function instantOf(time: EventTime, readerZone: string): number {
  const wall = wallOfTime(time)
  return 'date' in time ? toInstant(wall, readerZone) : toInstant(wall, zoneOr(time.timeZone, readerZone))
}

function seriesKey(event: SyncedCalendarEvent): string {
  return `${event.calendarId}\u0000${event.uid}`
}

function isSeries(event: SyncedCalendarEvent): boolean {
  return event.recurrence !== null || event.rdates.length > 0
}

function icalTime(wall: number, date: boolean): ICAL.Time {
  const parts = wallParts(wall)
  return date ? ICAL.Time.fromData({ year: parts.year, month: parts.month, day: parts.day, isDate: true }) : ICAL.Time.fromData({ ...parts, isDate: false })
}

function wallOfIcal(time: ICAL.Time): number {
  return wallOf({ year: time.year, month: time.month, day: time.day, hour: time.isDate ? 0 : time.hour, minute: time.isDate ? 0 : time.minute, second: time.isDate ? 0 : time.second })
}

/**
 * The starts (wall times in the series' zone) of a rule, from the series start on, while they are before `until`.
 * The series start always counts as the first occurrence (RFC 5545), also when the rule would not produce it.
 */
function* ruleStarts(rule: string, start: number, date: boolean, zone: string | null, readerZone: string, until: number): Generator<number> {
  const recur = ICAL.Recur.fromString(rule)
  if (recur.until !== null && recur.until !== undefined) {
    // Iterate in wall time: an UNTIL in UTC becomes the clock reading in the series' zone.
    const end = recur.until
    let wall: number
    if (end.isDate) {
      wall = date ? wallOfIcal(end) : wallOfIcal(end) + DAY - 1000
    } else if (end.zone?.tzid === 'UTC' && zone !== null) {
      wall = toWall(Date.UTC(end.year, end.month - 1, end.day, end.hour, end.minute, end.second), zoneOr(zone, readerZone))
    } else {
      wall = wallOfIcal(end)
    }
    if (date) {
      wall = Math.floor(wall / DAY) * DAY
    }
    recur.until = icalTime(wall, date)
  }
  const count = recur.count ?? null
  const iterator = recur.iterator(icalTime(start, date))
  let produced = 0
  let first = true
  for (let step = 0; step < MAX_STEPS; step++) {
    const next = iterator.next()
    if (next === null || next === undefined) {
      return
    }
    const wall = wallOfIcal(next)
    if (first) {
      first = false
      if (wall !== start) {
        produced += 1
        yield start
        if (count !== null && produced >= count) {
          return
        }
      }
    }
    if (wall >= until) {
      return
    }
    if (wall < start) {
      continue
    }
    produced += 1
    yield wall
    if (count !== null && produced >= count) {
      return
    }
  }
}

/** The occurrence of one row at its own times. */
function single(event: SyncedCalendarEvent, series: SyncedCalendarEvent | null, recurrenceId: string | null, recurring: boolean, first: boolean, readerZone: string): Occurrence {
  const start = instantOf(event.start, readerZone)
  const end = Math.max(start, instantOf(event.end, readerZone))
  return {
    key: `${event.id}:${recurrenceId ?? ''}`,
    event,
    seriesId: series?.id ?? event.id,
    series,
    recurrenceId,
    recurring,
    first,
    allDay: event.allDay,
    start,
    end,
    startDate: 'date' in event.start ? event.start.date : null,
    endDate: 'date' in event.end ? event.end.date : null,
    timeZone: 'dateTime' in event.start ? event.start.timeZone : null,
  }
}

function overlaps(occurrence: Occurrence, from: number, to: number): boolean {
  if (occurrence.end === occurrence.start) {
    return occurrence.start >= from && occurrence.start < to
  }
  return occurrence.start < to && occurrence.end > from
}

function dateOf(wall: number): string {
  const parts = wallParts(wall)
  return `${String(parts.year).padStart(4, '0')}-${String(parts.month).padStart(2, '0')}-${String(parts.day).padStart(2, '0')}`
}

/** The occurrences of a series in a range, overrides applied. */
function seriesOccurrences(master: SyncedCalendarEvent, overrides: Map<string, SyncedCalendarEvent>, from: number, to: number, readerZone: string): Occurrence[] {
  const date = 'date' in master.start
  const zone = 'dateTime' in master.start ? master.start.timeZone : null
  const readingZone = zoneOr(zone, readerZone)
  const startWall = wallOfTime(master.start)
  const endWall = wallOfTime(master.end)
  const startInstant = instantOf(master.start, readerZone)
  const length = date ? Math.max(0, Math.round((endWall - startWall) / DAY)) : Math.max(0, instantOf(master.end, readerZone) - startInstant)
  // Walk until the range ends, in the series' wall time, with a day to spare for zones and long events.
  const until = toWall(to, readingZone) + DAY + (date ? length * DAY : 0) + 14 * 3_600_000
  const earliest = toWall(from, readingZone) - DAY - (date ? length * DAY : length) - 14 * 3_600_000

  const keyOf = (wall: number): string => (date ? recurrenceKey(wall, true) : zone === null ? recurrenceKey(wall, false) : recurrenceKey(toInstant(wall, readingZone), false))
  const firstKey = keyOf(startWall)
  const exdates = new Set(master.exdates)
  const starts = new Map<string, number>()
  if (master.recurrence !== null) {
    for (const wall of ruleStarts(master.recurrence, startWall, date, zone, readerZone, until)) {
      if (wall >= earliest) {
        starts.set(keyOf(wall), wall)
      }
    }
  } else {
    starts.set(firstKey, startWall)
  }
  for (const rdate of master.rdates) {
    const value = parseRecurrenceKey(rdate)
    if (value === null) {
      continue
    }
    // Keys of timed series are UTC instants (floating ones their clock reading); dates are dates.
    const wall = date || zone === null ? value : toWall(value, readingZone)
    if (wall >= earliest && wall < until) {
      starts.set(keyOf(wall), wall)
    }
  }

  const occurrences: Occurrence[] = []
  const remaining = new Map(overrides)
  for (const [key, wall] of [...starts.entries()].sort((a, b) => a[1] - b[1])) {
    if (exdates.has(key)) {
      continue
    }
    const override = remaining.get(key)
    if (override !== undefined) {
      remaining.delete(key)
      occurrences.push(single(override, master, key, true, key === firstKey, readerZone))
      continue
    }
    const start = date ? toInstant(wall, readerZone) : toInstant(wall, readingZone)
    const end = date ? toInstant(wall + length * DAY, readerZone) : start + length
    occurrences.push({
      key: `${master.id}:${key}`,
      event: master,
      seriesId: master.id,
      series: master,
      recurrenceId: key,
      recurring: true,
      first: key === firstKey,
      allDay: master.allDay,
      start,
      end,
      startDate: date ? dateOf(wall) : null,
      endDate: date ? dateOf(wall + length * DAY) : null,
      timeZone: zone,
    })
  }
  // An override whose occurrence the series no longer has still shows.
  for (const [key, override] of remaining) {
    if (!exdates.has(key)) {
      occurrences.push(single(override, master, key, true, key === firstKey, readerZone))
    }
  }
  return occurrences.filter((occurrence) => overlaps(occurrence, from, to))
}

/**
 * The occurrences of events in a range [from, to) of instants, sorted by start and then by length (longest first).
 * Floating and all-day events are read in `readerZone`.
 */
export function expandOccurrences(events: Iterable<SyncedCalendarEvent>, from: number, to: number, readerZone: string): Occurrence[] {
  const masters = new Map<string, SyncedCalendarEvent>()
  const overrides = new Map<string, Map<string, SyncedCalendarEvent>>()
  for (const event of events) {
    const key = seriesKey(event)
    if (event.recurrenceId === null) {
      masters.set(key, event)
      continue
    }
    let forSeries = overrides.get(key)
    if (forSeries === undefined) {
      forSeries = new Map()
      overrides.set(key, forSeries)
    }
    forSeries.set(event.recurrenceId, event)
  }

  const occurrences: Occurrence[] = []
  for (const [key, master] of masters) {
    const own = overrides.get(key) ?? new Map<string, SyncedCalendarEvent>()
    overrides.delete(key)
    if (isSeries(master)) {
      occurrences.push(...seriesOccurrences(master, own, from, to, readerZone))
      continue
    }
    const occurrence = single(master, null, null, false, true, readerZone)
    if (overlaps(occurrence, from, to)) {
      occurrences.push(occurrence)
    }
  }
  // Occurrences the user was invited to without their series: each is an event of its own, edited as "this event".
  for (const forSeries of overrides.values()) {
    for (const [recurrenceId, override] of forSeries) {
      const occurrence = single(override, null, recurrenceId, false, true, readerZone)
      if (overlaps(occurrence, from, to)) {
        occurrences.push(occurrence)
      }
    }
  }
  return occurrences.sort((a, b) => a.start - b.start || b.end - b.start - (a.end - a.start) || a.key.localeCompare(b.key))
}

/**
 * The instants a stored row can touch, for finding the rows a range needs: from its first start to the end of its
 * last occurrence, null when the series has no end (or one only expanding it can tell, a COUNT).
 */
export function eventSpan(event: SyncedCalendarEvent): { startsAt: number; endsAt: number | null } {
  // Read in UTC and widened by 14 hours on each side, like the server's range columns: all-day and floating events
  // fall on another instant in every zone.
  const widen = 14 * 3_600_000
  const floating = 'date' in event.start || event.start.timeZone === null
  const startsAt = instantOf(event.start, 'UTC') - (floating ? widen : 0)
  const firstEnd = Math.max(instantOf(event.end, 'UTC'), instantOf(event.start, 'UTC')) + (floating ? widen : 0)
  if (!isSeries(event)) {
    return { startsAt, endsAt: firstEnd }
  }
  const length = firstEnd - startsAt
  let endsAt = firstEnd
  for (const rdate of event.rdates) {
    const value = parseRecurrenceKey(rdate)
    if (value !== null) {
      endsAt = Math.max(endsAt, value + length + DAY)
    }
  }
  if (event.recurrence === null) {
    return { startsAt, endsAt }
  }
  const until = /(?:^|;)UNTIL=(\d{8}(?:T\d{6}Z?)?)/.exec(event.recurrence)?.[1]
  if (/(?:^|;)COUNT=/.test(event.recurrence) || until === undefined) {
    return { startsAt, endsAt: null }
  }
  const untilAt = parseRecurrenceKey(until.endsWith('Z') || until.length === 8 ? until : `${until}Z`)
  return { startsAt, endsAt: untilAt === null ? null : Math.max(endsAt, untilAt + length + DAY) }
}
