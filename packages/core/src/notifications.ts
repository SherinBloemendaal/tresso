/**
 * Wire format of notifications: /api/v1/notifications, /notification-devices, /notification-settings,
 * /reminder-rules, and the `notifications` of the delta.
 */

export type NotificationKind = 'reminder' | 'leave_now' | 'brief' | 'follow_up'
export type NotificationChannel = 'push' | 'email'
export type DeviceKind = 'web_push' | 'expo' | 'desktop'

/** A delivered notification as the delta carries it; every device shows it and clears it once dismissed. */
export interface SyncedNotification {
  id: string
  kind: NotificationKind
  eventId: string | null
  recurrenceId: string | null
  title: string
  body: string
  occurrenceStartsAt: string
  deliveredAt: string
  dismissedAt: string | null
  /** Delivered during quiet hours or focus: show it without sound or banner. */
  silent: boolean
  /** Raised by every snooze, so a device shows the notification again. */
  round: number
}

/** A notification from /api/v1/notifications: delivered in the last week or planned for the coming days. */
export interface PlannedNotification {
  id: string
  kind: NotificationKind
  eventId: string | null
  recurrenceId: string | null
  title: string
  body: string
  channels: NotificationChannel[]
  fireAt: string
  occurrenceStartsAt: string
  deliveredAt: string | null
  dismissedAt: string | null
  silent: boolean
}

export interface NotificationDevice {
  id: string
  kind: DeviceKind
  name: string
  enabled: boolean
  createdAt: string
  lastSeenAt: string
}

/** POST /api/v1/notification-devices; the same endpoint again updates the device. */
export interface NewNotificationDevice {
  kind: DeviceKind
  /** Web push: the subscription endpoint. Expo: the ExponentPushToken. Desktop: an installation id. */
  endpoint: string
  /** Web push: the subscription's keys.p256dh and keys.auth (base64url). */
  publicKey?: string
  authSecret?: string
  name: string
}

export interface NotificationSettings {
  timeZone: string
  quietHoursEnabled: boolean
  /** ISO days, 1 is Monday. */
  quietDays: number[]
  /** HH:MM; quiet hours past midnight end the next day. */
  quietStart: string
  quietEnd: string
  focusDuringBusy: boolean
  emailAddress: string | null
  effectiveEmailAddress: string
}

export type RuleRole = 'any' | 'organized' | 'invited'

export interface RuleReminder {
  minutesBefore: number | null
  /** HH:MM on the day before, for all-day events. */
  dayBeforeAt: string | null
  channels: NotificationChannel[]
}

export interface ReminderRule {
  id: string
  name: string
  calendarId: string | null
  allDay: boolean | null
  role: RuleRole
  withAttendees: boolean | null
  withConference: boolean | null
  withLocation: boolean | null
  reminders: RuleReminder[]
  leaveNow: boolean
  briefMinutes: number | null
  followUp: boolean
  position: number
}

/** The user's private note on an event or one occurrence. */
export interface SyncedEventNote {
  id: string
  eventId: string
  recurrenceId: string | null
  text: string
  updatedAt: string
}

export interface MeetingBrief {
  eventId: string
  recurrenceId: string | null
  people: { address: string; name: string | null; response: string }[]
  threads: { threadId: string; mailAccountId: string; subject: string; lastMessageAt: string; messageCount: number }[]
  summary: string | null
  headline: string
}
