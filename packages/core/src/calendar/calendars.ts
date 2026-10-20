import type { SyncedCalendar } from './types'

/** The user's order: by position, then the Tresso calendar first, then by name. */
export function sortCalendars<T extends Pick<SyncedCalendar, 'position' | 'name' | 'id' | 'mailAccountId'>>(calendars: T[]): T[] {
  return [...calendars].sort(
    (a, b) => a.position - b.position || Number(a.mailAccountId !== null) - Number(b.mailAccountId !== null) || a.name.localeCompare(b.name) || a.id.localeCompare(b.id),
  )
}

/** Where a new event goes: the default calendar of the account, else the first writable one. */
export function defaultCalendar(calendars: SyncedCalendar[]): SyncedCalendar | null {
  const writable = calendars.filter((calendar) => calendar.writable)
  return writable.find((calendar) => calendar.isDefault && calendar.mailAccountId === null) ?? writable.find((calendar) => calendar.isDefault) ?? writable[0] ?? null
}
