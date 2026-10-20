/**
 * Wire format of notifications: /api/v1/notifications, /notification-devices, /notification-settings,
 * /reminder-rules, and the `notifications` and `mailNotifications` of the delta.
 */

export type NotificationKind = 'reminder' | 'leave_now' | 'brief' | 'follow_up' | 'mail'
/** The phone app (Expo push), the desktop app and browsers (web push), or email. */
export type NotificationChannel = 'phone' | 'desktop' | 'email'
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
  /** Null while snoozed: devices hide it until it is delivered again. */
  deliveredAt: string | null
  dismissedAt: string | null
  /** Delivered during quiet hours or focus: show it without sound or banner. */
  silent: boolean
  /** Raised by every snooze, so a device shows the notification again. */
  round: number
  /** When a snoozed notification comes again; null otherwise. */
  snoozedUntil: string | null
}

/**
 * A delivered notification about new mail that needs the user (judged `do` or `delegate`, unread, in an inbox), as the
 * delta carries it: the desktop app shows the ones sent to the desktop. The phone and browsers got theirs by push.
 */
export interface SyncedMailNotification {
  id: string
  /** Null once the message or conversation is gone. */
  messageId: string | null
  threadId: string | null
  /** The sender. */
  title: string
  /** The subject (with "To hand over: " for `delegate`), and the start of the text on a second line. */
  body: string
  deliveredAt: string
  channels: NotificationChannel[]
  /** Delivered during quiet hours or another busy event: show it without sound. */
  silent: boolean
  /** Stands for several messages a batch delivered ("5 new messages to do"): it opens the batches. */
  summary?: boolean
}

/** Where a tap on a mail notification leads, in every client: the conversation. */
export function mailNotificationPath(threadId: string): string {
  return `/thread/${threadId}`
}

/** Where a tap on a batch's summary leads, in every client; mirrors MailNotifier::BATCHES_PATH. */
export const BATCHES_PATH = '/batches'

/**
 * Where a tap on this mail notification leads: its conversation, or the batches for a summary of several (a summary
 * that only one message still needed carries that one's conversation); null when the conversation is gone.
 */
export function mailNotificationTarget(notification: Pick<SyncedMailNotification, 'threadId' | 'summary'>): string | null {
  if (notification.threadId !== null) {
    return mailNotificationPath(notification.threadId)
  }
  return notification.summary ? BATCHES_PATH : null
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
  /** The user's channels per kind of notification; a kind missing here uses its default. */
  channelsByKind: Record<string, NotificationChannel[]>
  /** ISO days (1 is Monday) with working hours, from workStart to workEnd (HH:MM). */
  workDays: number[]
  workStart: string
  workEnd: string
  /** Early reminders of work calendars come at the end of the working day before. */
  shiftEarlyReminders: boolean
  /** Work calendars notify within working hours only, except leave-now and important events. */
  workCalendarsQuiet: boolean
  /** Notifications wait for the end of a focus block; meetings with others and leave-now come through. */
  holdDuringFocus: boolean
  /** Mail notifications wait while the user is in a meeting. */
  holdMailInMeetings: boolean
  /**
   * Mail notifications show the sender, subject and the start of the text; false: only "New mail from {account}".
   * Phone pushes pass through Expo, Apple and Google unencrypted; Web Push is end-to-end encrypted.
   */
  mailPreviews: boolean
  /** Leave-now reminders for events with a place. */
  leaveNow: boolean
  travelMode: TravelMode
  /** Minutes added to the travel time. */
  extraTravelMinutes: number
  /** The snooze choices a reminder offers, in order. */
  snoozeChoices: SnoozeChoice[]
}

export type TravelMode = 'walk' | 'bike' | 'transit' | 'car'

export type SnoozeChoice = 'minutes_5' | 'minutes_10' | 'minutes_15' | 'before_start' | 'start' | 'meeting_end'

/** POST /api/v1/calendar-events/{id}/leave-estimate: the phone's position, for one estimate only. */
export interface LeaveEstimateRequest {
  latitude: number
  longitude: number
  recurrenceId: string | null
}

export interface LeaveEstimate {
  minutes: number | null
  leaveAt: string | null
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
