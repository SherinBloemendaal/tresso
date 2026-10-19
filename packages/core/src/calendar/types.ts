/** Wire format of calendars and events: in the delta, and from /api/v1/calendars and /api/v1/calendar-events. */

import type { Address } from '../sync/types'

export type CalendarSource = 'local' | 'caldav' | 'google' | 'microsoft'

/** A calendar of the user: at the provider of a mail account, or local to Tresso (`mailAccountId` null). */
export interface SyncedCalendar {
  id: string
  mailAccountId: string | null
  source: CalendarSource
  name: string
  /** The user's color when they chose one, else the provider's. */
  color: string
  timeZone: string | null
  writable: boolean
  isDefault: boolean
  visible: boolean
  /** The provider sends invitations and replies itself. */
  providerSchedules: boolean
  position: number
}

/** A date for all-day events; a wall-clock time with its IANA zone otherwise (null zone: floating). */
export type EventTime = { date: string } | { dateTime: string; timeZone: string | null }

export type EventStatus = 'confirmed' | 'tentative' | 'cancelled'
export type ParticipationStatus = 'needs-action' | 'accepted' | 'declined' | 'tentative' | 'delegated'
export type AttendeeRole = 'required' | 'optional' | 'chair' | 'non-participant'

export interface Attendee {
  address: string
  name: string | null
  role: AttendeeRole
  partstat: ParticipationStatus
  rsvp: boolean
  kind: 'individual' | 'group' | 'resource' | 'room' | 'unknown'
}

export interface Reminder {
  action: string
  /** An iCalendar duration relative to `relatedTo` (-PT15M), or null for an absolute reminder. */
  trigger: string | null
  relatedTo: 'start' | 'end'
  /** The absolute time (YYYYMMDDTHHMMSSZ), or null. */
  at: string | null
  channels: ('push' | 'email')[]
}

export interface Conference {
  provider: 'teams' | 'meet' | 'zoom' | 'webex' | 'other'
  url: string
  id: string | null
  dialIn: string | null
}

/**
 * An event as stored: a single event, the master of a series (`recurrence`, `rdates`, `exdates`), or the override of
 * one occurrence (`recurrenceId`). Recurrence ids are YYYYMMDD for all-day series, YYYYMMDDTHHMMSSZ otherwise.
 */
export interface SyncedCalendarEvent {
  id: string
  calendarId: string
  uid: string
  recurrenceId: string | null
  sequence: number
  status: EventStatus
  summary: string
  description: string
  location: string
  url: string | null
  transparency: 'busy' | 'free'
  visibility: 'public' | 'private' | 'confidential'
  allDay: boolean
  start: EventTime
  /** Exclusive for all-day events. */
  end: EventTime
  recurrence: string | null
  rdates: string[]
  exdates: string[]
  organizer: Address | null
  attendees: Attendee[]
  /** The user's own response when invited; null when they organize or nobody is invited. */
  selfResponse: ParticipationStatus | null
  reminders: Reminder[]
  conference: Conference | null
  /** The invitation this event came from. */
  messageId: string | null
  /** Why the last change did not reach the provider. */
  syncError: string | null
  /** Minutes to travel there, set by the user. */
  travelMinutes: number | null
  /** No reminders of its own: the user's default rules apply. */
  defaultReminders: boolean
}
