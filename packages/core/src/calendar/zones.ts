import { deviceTimeZone } from '../timeZone'

/**
 * Wall-clock time and IANA time zones without a time zone library: the platform's Intl data answers what the clock
 * shows at an instant, and the rest follows from that.
 *
 * A wall time is a plain number: the milliseconds Date.UTC gives for the clock reading, so adding a day is adding
 * 86,400,000 and comparing two readings is comparing numbers. Only {@link toInstant} and {@link toWall} cross between
 * wall time and real instants.
 */

export const MINUTE = 60_000
export const HOUR = 60 * MINUTE
export const DAY = 24 * HOUR

export interface WallParts {
  year: number
  /** 1 to 12. */
  month: number
  day: number
  hour: number
  minute: number
  second: number
}

const formatters = new Map<string, Intl.DateTimeFormat>()

function partsFormatter(zone: string): Intl.DateTimeFormat {
  let formatter = formatters.get(zone)
  if (formatter === undefined) {
    formatter = new Intl.DateTimeFormat('en-US', {
      timeZone: zone,
      hourCycle: 'h23',
      year: 'numeric',
      month: 'numeric',
      day: 'numeric',
      hour: 'numeric',
      minute: 'numeric',
      second: 'numeric',
      era: 'short',
    })
    formatters.set(zone, formatter)
  }
  return formatter
}

/** Whether the platform knows the zone. */
export function isTimeZone(zone: string): boolean {
  try {
    partsFormatter(zone)
    return true
  } catch {
    return false
  }
}

/** The zone the calendar is read in: the device's, or UTC when the runtime does not tell. */
export function readerTimeZone(): string {
  return deviceTimeZone() ?? 'UTC'
}

/** The clock reading in a zone at an instant. */
export function toWall(instant: number, zone: string): number {
  const values: Record<string, string> = {}
  for (const part of partsFormatter(zone).formatToParts(new Date(instant))) {
    values[part.type] = part.value
  }
  const year = values.era === 'BC' || values.era === 'B' ? 1 - Number(values.year) : Number(values.year)
  const wall = Date.UTC(year, Number(values.month) - 1, Number(values.day), Number(values.hour), Number(values.minute), Number(values.second))
  // Intl leaves out milliseconds; they never depend on the zone.
  return wall + (((instant % 1000) + 1000) % 1000)
}

/** Minutes the zone is ahead of UTC at an instant. */
export function offsetAt(instant: number, zone: string): number {
  return Math.round((toWall(instant, zone) - instant) / MINUTE)
}

/**
 * The instant a clock reading in a zone stands for, as RFC 5545 reads one: a reading that happens twice (when clocks
 * go back) is the first of the two, and one that never happens (when clocks go forward) is read with the offset
 * from before the change, so it lands as far after the change as it was meant to be after the old time.
 */
export function toInstant(wall: number, zone: string): number {
  const before = offsetAt(wall - DAY, zone)
  const after = offsetAt(wall + DAY, zone)
  const candidates = [...new Set([before, after])].map((offset) => wall - offset * MINUTE).filter((instant) => toWall(instant, zone) === wall)
  if (candidates.length > 0) {
    return Math.min(...candidates)
  }
  return wall - before * MINUTE
}

export function wallParts(wall: number): WallParts {
  const date = new Date(wall)
  return {
    year: date.getUTCFullYear(),
    month: date.getUTCMonth() + 1,
    day: date.getUTCDate(),
    hour: date.getUTCHours(),
    minute: date.getUTCMinutes(),
    second: date.getUTCSeconds(),
  }
}

export function wallOf(parts: Partial<WallParts> & Pick<WallParts, 'year' | 'month' | 'day'>): number {
  return Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour ?? 0, parts.minute ?? 0, parts.second ?? 0)
}

/** Midnight of the day a wall time falls on. */
export function startOfWallDay(wall: number): number {
  return Math.floor(wall / DAY) * DAY
}

/** 0 for Sunday to 6 for Saturday. */
export function weekdayOf(wall: number): number {
  return new Date(wall).getUTCDay()
}

/** The same clock time a number of calendar days later (or earlier). */
export function addDays(wall: number, days: number): number {
  return wall + days * DAY
}

/** The same day of the month a number of months later, held to the month's last day. */
export function addMonths(wall: number, months: number): number {
  const parts = wallParts(wall)
  const first = Date.UTC(parts.year, parts.month - 1 + months, 1)
  const last = new Date(Date.UTC(parts.year, parts.month + months, 0)).getUTCDate()
  return first + (Math.min(parts.day, last) - 1) * DAY + (wall - startOfWallDay(wall))
}

const pad = (value: number, length = 2): string => String(value).padStart(length, '0')

/** 2026-10-19 */
export function formatDate(wall: number): string {
  const parts = wallParts(wall)
  return `${pad(parts.year, 4)}-${pad(parts.month)}-${pad(parts.day)}`
}

/** 2026-10-19T09:00:00 */
export function formatDateTime(wall: number): string {
  const parts = wallParts(wall)
  return `${formatDate(wall)}T${pad(parts.hour)}:${pad(parts.minute)}:${pad(parts.second)}`
}

/** Reads 2026-10-19 or 2026-10-19T09:00[:00]; null when it is neither. */
export function parseWall(value: string): number | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2})(?::(\d{2}))?)?$/.exec(value)
  if (match === null) {
    return null
  }
  const [, year, month, day, hour, minute, second] = match.map(Number) as number[]
  const wall = Date.UTC(year!, month! - 1, day!, Number.isNaN(hour) ? 0 : hour!, Number.isNaN(minute) ? 0 : minute!, Number.isNaN(second) ? 0 : second!)
  const parts = wallParts(wall)
  return parts.month === month && parts.day === day ? wall : null
}

/** A recurrence id (YYYYMMDD, or YYYYMMDDTHHMMSSZ) as a date's midnight or an instant, read as UTC. */
export function parseRecurrenceKey(key: string): number | null {
  const match = /^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})Z)?$/.exec(key)
  if (match === null) {
    return null
  }
  const [, year, month, day, hour, minute, second] = match.map(Number) as number[]
  return Date.UTC(year!, month! - 1, day!, Number.isNaN(hour) ? 0 : hour!, Number.isNaN(minute) ? 0 : minute!, Number.isNaN(second) ? 0 : second!)
}

/** The recurrence id of an all-day occurrence (a wall date) or a timed one (an instant). */
export function recurrenceKey(value: number, date: boolean): string {
  const parts = wallParts(value)
  const day = `${pad(parts.year, 4)}${pad(parts.month)}${pad(parts.day)}`
  return date ? day : `${day}T${pad(parts.hour)}${pad(parts.minute)}${pad(parts.second)}Z`
}

/** UTC+2, UTC-4, UTC+5:30, UTC */
export function offsetLabel(minutes: number): string {
  if (minutes === 0) {
    return 'UTC'
  }
  const sign = minutes > 0 ? '+' : '-'
  const absolute = Math.abs(minutes)
  const rest = absolute % 60
  return `UTC${sign}${Math.floor(absolute / 60)}${rest === 0 ? '' : `:${pad(rest)}`}`
}

const abbreviations = new Map<string, Intl.DateTimeFormat>()

/** The zone's short name at an instant (CEST, EDT), or null when the platform only knows it as an offset. */
export function zoneAbbreviation(instant: number, zone: string): string | null {
  let formatter = abbreviations.get(zone)
  if (formatter === undefined) {
    formatter = new Intl.DateTimeFormat('en-GB', { timeZone: zone, timeZoneName: 'short' })
    abbreviations.set(zone, formatter)
  }
  const name = formatter.formatToParts(new Date(instant)).find((part) => part.type === 'timeZoneName')?.value ?? null
  if (name === null || /^(GMT|UTC)([+-]|$)/.test(name)) {
    return name === 'UTC' ? 'UTC' : null
  }
  return name
}

/** The city of a zone id: Europe/Amsterdam is Amsterdam, America/Argentina/Buenos_Aires is Buenos Aires. */
export function zoneCity(zone: string): string {
  if (zone === 'UTC' || zone === 'Etc/UTC') {
    return 'UTC'
  }
  return (zone.split('/').pop() ?? zone).replace(/_/g, ' ')
}

/** "Amsterdam, CEST (UTC+2)", city first, as the calendar names zones; "UTC" for UTC itself. */
export function zoneLabel(zone: string, instant: number): string {
  const city = zoneCity(zone)
  const offset = offsetLabel(offsetAt(instant, zone))
  if (city === 'UTC') {
    return 'UTC'
  }
  const abbreviation = zoneAbbreviation(instant, zone)
  return abbreviation === null || abbreviation === 'UTC' ? `${city} (${offset})` : `${city}, ${abbreviation} (${offset})`
}

/** The zones the platform knows, for choosing one; the device's own zone when it lists none. */
export function knownTimeZones(): string[] {
  const supported = (Intl as unknown as { supportedValuesOf?: (key: string) => string[] }).supportedValuesOf
  const zones = supported === undefined ? [] : supported('timeZone')
  return zones.length > 0 ? zones : [readerTimeZone()]
}
