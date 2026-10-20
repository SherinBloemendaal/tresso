import type { Occurrence } from './occurrences'
import type { ParticipationStatus } from './types'
import { addDays, DAY, parseWall, startOfWallDay, toWall, wallParts, weekdayOf, utc } from './zones'

export const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as const
export const WEEKDAYS_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as const
export const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'] as const
export const MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] as const

const pad = (value: number): string => String(value).padStart(2, '0')

/** 09:30, on the 24 hour clock. */
export function clock(wall: number): string {
  const parts = wallParts(wall)
  return `${pad(parts.hour)}:${pad(parts.minute)}`
}

/** 09:30 in a zone. */
export function timeIn(instant: number, zone: string): string {
  return clock(toWall(instant, zone))
}

/** "Thursday 23 October", with the year when it is not the year of `today`. */
export function longDate(wall: number, today?: number): string {
  const parts = wallParts(wall)
  const year = today !== undefined && wallParts(today).year !== parts.year ? ` ${parts.year}` : ''
  return `${WEEKDAYS[weekdayOf(wall)]} ${parts.day} ${MONTH_NAMES[parts.month - 1]}${year}`
}

/** "Thu 23 Oct" */
export function shortDate(wall: number): string {
  const parts = wallParts(wall)
  return `${WEEKDAYS_SHORT[weekdayOf(wall)]} ${parts.day} ${MONTHS_SHORT[parts.month - 1]}`
}

/** "Today", "Tomorrow", "Yesterday", else the long date. Both are wall times. */
export function relativeDay(wall: number, today: number): string {
  const difference = Math.round((startOfWallDay(wall) - startOfWallDay(today)) / DAY)
  if (difference === 0) return 'Today'
  if (difference === 1) return 'Tomorrow'
  if (difference === -1) return 'Yesterday'
  return longDate(wall, today)
}

/** "10:00 to 11:00", the calendar's way of writing a time range (never a dash). Times in the reader's zone. */
export function occurrenceTimes(occurrence: Occurrence, zone: string): string {
  if (occurrence.allDay) {
    const first = occurrence.startDate === null ? null : parseWall(occurrence.startDate)
    const after = occurrence.endDate === null ? null : parseWall(occurrence.endDate)
    if (first === null || after === null || after - first <= DAY) {
      return 'All day'
    }
    return `${shortDate(first)} to ${shortDate(addDays(after, -1))}`
  }
  const start = toWall(occurrence.start, zone)
  const end = toWall(occurrence.end, zone)
  if (startOfWallDay(start) === startOfWallDay(end) || (end === startOfWallDay(end) && end - start <= DAY)) {
    return `${clock(start)} to ${clock(end)}`
  }
  return `${shortDate(start)} ${clock(start)} to ${shortDate(end)} ${clock(end)}`
}

/** "Thursday 23 October, 10:00 to 11:00" */
export function occurrenceWhen(occurrence: Occurrence, zone: string, today?: number): string {
  const day = occurrence.allDay && occurrence.startDate !== null ? (parseWall(occurrence.startDate) ?? toWall(occurrence.start, zone)) : toWall(occurrence.start, zone)
  return `${longDate(day, today)}, ${occurrenceTimes(occurrence, zone)}`
}

/** ISO 8601 week number of the week a day is in. */
export function isoWeek(wall: number): number {
  const day = startOfWallDay(wall)
  const thursday = day + (3 - ((weekdayOf(day) + 6) % 7)) * DAY
  const yearStart = utc(wallParts(thursday).year, 0, 1)
  return Math.floor((thursday - yearStart) / DAY / 7) + 1
}

/** Midnight of the first day of the week a day is in; weeks start on `weekStart` (1 Monday, 0 Sunday). */
export function startOfWeek(wall: number, weekStart = 1): number {
  const day = startOfWallDay(wall)
  return day - ((weekdayOf(day) - weekStart + 7) % 7) * DAY
}

/** Midnight of the first of the month a day is in. */
export function startOfMonth(wall: number): number {
  const parts = wallParts(wall)
  return utc(parts.year, parts.month - 1, 1)
}

/** How lists name a guest's answer. */
export const RESPONSE_WORDS: Record<ParticipationStatus, string> = {
  accepted: 'Going',
  tentative: 'Maybe',
  declined: 'Not going',
  'needs-action': 'No reply',
  delegated: 'Delegated',
}

/** "5 people: 2 going, 1 maybe, 2 no reply" */
export function attendeeSummary(statuses: ParticipationStatus[]): string {
  if (statuses.length === 0) {
    return ''
  }
  const counts = new Map<ParticipationStatus, number>()
  for (const status of statuses) {
    counts.set(status, (counts.get(status) ?? 0) + 1)
  }
  const order: ParticipationStatus[] = ['accepted', 'tentative', 'declined', 'needs-action', 'delegated']
  const parts = order.filter((status) => counts.has(status)).map((status) => `${counts.get(status)} ${RESPONSE_WORDS[status].toLowerCase()}`)
  return `${statuses.length} ${statuses.length === 1 ? 'person' : 'people'}: ${parts.join(', ')}`
}
