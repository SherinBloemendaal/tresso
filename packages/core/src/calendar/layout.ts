import type { Occurrence } from './occurrences'
import { addDays, DAY, MINUTE, toInstant, toWall } from './zones'

/** A day in the reader's zone: its date as a wall time and the instants it starts and ends (23 or 25 hours at DST). */
export interface CalendarDay {
  /** Midnight of the day as a wall time. */
  wall: number
  start: number
  end: number
}

/** The days from one wall date on, in a zone. */
export function daysFrom(firstWall: number, count: number, zone: string): CalendarDay[] {
  const days: CalendarDay[] = []
  for (let index = 0; index < count; index++) {
    const wall = addDays(firstWall, index)
    days.push({ wall, start: toInstant(wall, zone), end: toInstant(addDays(wall, 1), zone) })
  }
  return days
}

/**
 * Whether an occurrence belongs in the all-day row rather than on the time grid: all-day events, and timed events of
 * a day or longer, as Google Calendar and Outlook show them.
 */
export function inAllDayRow(occurrence: Occurrence): boolean {
  return occurrence.allDay || occurrence.end - occurrence.start >= DAY
}

/** A piece of a timed occurrence on one day's time grid. */
export interface TimedBlock {
  occurrence: Occurrence
  /** Minutes after the day's midnight on the clock, so a 09:00 start is at 540 also on a DST day. */
  top: number
  /** At least {@link minMinutes}, so a short event stays readable and clickable. */
  height: number
  /** Overlapping blocks cascade: this block's place among those it overlaps, and how many there are. */
  column: number
  columns: number
  /** The occurrence started on an earlier day, or goes on after this one. */
  continuesBefore: boolean
  continuesAfter: boolean
}

/**
 * Lays out the timed occurrences of one day: each is cut to the day, and blocks that overlap in time share the width.
 * A block takes the first column no earlier block of its group still occupies, so a group is as narrow as it can be.
 * Clock minutes come from the zone, so blocks sit at the hour their times say on days with a DST change.
 */
export function layoutDay(occurrences: Occurrence[], day: CalendarDay, zone: string, minMinutes = 15): TimedBlock[] {
  const pieces = occurrences
    .filter((occurrence) => !inAllDayRow(occurrence) && occurrence.start < day.end && (occurrence.end > day.start || (occurrence.end === occurrence.start && occurrence.start >= day.start)))
    .map((occurrence) => {
      const start = Math.max(occurrence.start, day.start)
      const end = Math.min(occurrence.end, day.end)
      const top = Math.round((toWall(start, zone) - day.wall) / MINUTE)
      const bottom = end >= day.end ? 24 * 60 : Math.round((toWall(end, zone) - day.wall) / MINUTE)
      return { occurrence, top, bottom: Math.max(bottom, top + minMinutes), continuesBefore: occurrence.start < day.start, continuesAfter: occurrence.end > day.end }
    })
    .sort((a, b) => a.top - b.top || b.bottom - a.bottom || a.occurrence.key.localeCompare(b.occurrence.key))

  const blocks: TimedBlock[] = []
  let group: { block: TimedBlock; bottom: number }[] = []
  let groupEnd = -1
  const close = (): void => {
    const columns = Math.max(0, ...group.map((entry) => entry.block.column)) + 1
    for (const entry of group) {
      entry.block.columns = columns
    }
    group = []
  }
  for (const piece of pieces) {
    if (piece.top >= groupEnd && group.length > 0) {
      close()
    }
    const taken = new Set(group.filter((entry) => entry.bottom > piece.top).map((entry) => entry.block.column))
    let column = 0
    while (taken.has(column)) {
      column++
    }
    const block: TimedBlock = {
      occurrence: piece.occurrence,
      top: piece.top,
      height: Math.min(piece.bottom, 24 * 60) - piece.top,
      column,
      columns: 1,
      continuesBefore: piece.continuesBefore,
      continuesAfter: piece.continuesAfter,
    }
    blocks.push(block)
    group.push({ block, bottom: piece.bottom })
    groupEnd = Math.max(groupEnd, piece.bottom)
  }
  if (group.length > 0) {
    close()
  }
  return blocks
}

/** An occurrence in a row of days (the all-day row of a week, a week of the month): which days it covers, on which lane. */
export interface SpanItem {
  occurrence: Occurrence
  /** Index of the first and last day it covers within the row. */
  first: number
  last: number
  lane: number
  continuesBefore: boolean
  continuesAfter: boolean
}

/** The days of a row an occurrence covers, or null when it misses them. All-day ends are exclusive dates. */
function covered(occurrence: Occurrence, days: CalendarDay[]): { first: number; last: number } | null {
  let first = -1
  let last = -1
  days.forEach((day, index) => {
    const touches = occurrence.end === occurrence.start ? occurrence.start >= day.start && occurrence.start < day.end : occurrence.start < day.end && occurrence.end > day.start
    if (touches) {
      if (first === -1) first = index
      last = index
    }
  })
  return first === -1 ? null : { first, last }
}

/**
 * Packs occurrences into lanes across a row of days: longer and earlier ones first, each on the first lane that is
 * free over all its days. Used for the all-day row of the week and day views and for the weeks of the month view.
 */
export function layoutSpans(occurrences: Occurrence[], days: CalendarDay[]): SpanItem[] {
  if (days.length === 0) {
    return []
  }
  const rowStart = days[0]!.start
  const rowEnd = days[days.length - 1]!.end
  const placed = occurrences
    .map((occurrence) => ({ occurrence, range: covered(occurrence, days) }))
    .filter((entry): entry is { occurrence: Occurrence; range: { first: number; last: number } } => entry.range !== null)
    .sort((a, b) => {
      const spanA = a.range.last - a.range.first
      const spanB = b.range.last - b.range.first
      // Multi-day first, then all-day before timed, then by start.
      return (
        a.range.first - b.range.first ||
        spanB - spanA ||
        Number(inAllDayRow(b.occurrence)) - Number(inAllDayRow(a.occurrence)) ||
        a.occurrence.start - b.occurrence.start ||
        a.occurrence.key.localeCompare(b.occurrence.key)
      )
    })

  const lanes: boolean[][] = []
  return placed.map(({ occurrence, range }) => {
    let lane = 0
    for (; ; lane++) {
      const row = (lanes[lane] ??= [])
      let free = true
      for (let index = range.first; index <= range.last; index++) {
        if (row[index]) {
          free = false
          break
        }
      }
      if (free) {
        for (let index = range.first; index <= range.last; index++) {
          row[index] = true
        }
        break
      }
    }
    return { occurrence, first: range.first, last: range.last, lane, continuesBefore: occurrence.start < rowStart, continuesAfter: occurrence.end > rowEnd }
  })
}

/**
 * What a day cell of the month view shows: the items on lanes below `visible`, and how many it leaves out ("+N
 * more"). An item that spans several days counts as left out on every day it covers.
 */
export function cellOverflow(items: SpanItem[], dayIndex: number, visible: number): { shown: SpanItem[]; more: number } {
  const onDay = items.filter((item) => item.first <= dayIndex && item.last >= dayIndex)
  const total = onDay.length
  if (total <= visible) {
    return { shown: onDay, more: 0 }
  }
  // One lane goes to the "+N more" line.
  const shown = onDay.filter((item) => item.lane < visible - 1)
  return { shown, more: total - shown.length }
}
