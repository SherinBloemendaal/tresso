import { computed, ref, shallowRef, watch } from 'vue'
import { defineStore } from 'pinia'
import {
  addDays,
  addMonths,
  daysFrom,
  defaultCalendar,
  deleteRequest,
  readerTimeZone,
  expandOccurrences,
  saveRequest,
  startOfMonth,
  startOfWallDay,
  startOfWeek,
  toWall,
  type CalendarDay,
  type EditScope,
  type EventInput,
  type Occurrence,
  type SyncedCalendar,
  type SyncedCalendarEvent,
} from '@tresso/core'
import { useAuthStore } from '@/stores/auth'
import { useMailStore } from '@/stores/mail'

export type CalendarViewKind = 'day' | 'week' | 'month' | 'agenda'

/** How many days the agenda lists from its first day. */
export const AGENDA_DAYS = 30

/** The invitation a message carries, as GET /api/v1/messages/{id}/invitation answers. */
export interface MessageInvitation {
  id: string
  method: string
  kind: 'new' | 'update' | 'cancelled' | 'reply' | 'outdated'
  uid: string
  recurrenceId: string | null
  sequence: number
  summary: string
  description: string
  location: string
  allDay: boolean
  start: SyncedCalendarEvent['start']
  end: SyncedCalendarEvent['end']
  recurrence: string | null
  organizer: SyncedCalendarEvent['organizer']
  attendees: SyncedCalendarEvent['attendees']
  conference: SyncedCalendarEvent['conference']
  eventId: string | null
  selfResponse: SyncedCalendarEvent['selfResponse']
  canRespond: boolean
  conflicts: InvitationConflict[]
  canApply: boolean
}

/** An occurrence that overlaps an invitation, as the API lists it. */
export interface InvitationConflict {
  id: string
  eventId: string
  summary: string
  allDay: boolean
  start: { date: string } | { dateTime: string }
  end: { date: string } | { dateTime: string }
  selfResponse: SyncedCalendarEvent['selfResponse']
}

export type Response = 'accepted' | 'tentative' | 'declined'

/**
 * The calendar views' state: which days are shown, in which zone, and the occurrences in them, expanded on the device
 * from the synced copy so moving between weeks needs no request. Changes go through the API; the delta brings them
 * back into the copy.
 */
export const useCalendarStore = defineStore('calendar', () => {
  const auth = useAuthStore()
  const mail = useMailStore()

  const zone = ref(readerTimeZone())
  const view = ref<CalendarViewKind>('week')
  /** The day the view is about, as a wall date in {@link zone}. */
  const anchor = ref(startOfWallDay(toWall(Date.now(), zone.value)))
  /** The instant the views treat as now; the views move it on every minute. */
  const now = ref(Date.now())
  const loaded = shallowRef<Occurrence[]>([])
  /** Rows of the range on screen, for finding a series and its overrides. */
  const rows = shallowRef<SyncedCalendarEvent[]>([])
  const loading = ref(false)

  const calendars = computed(() => mail.calendars)
  const calendarsById = computed(() => new Map(calendars.value.map((calendar) => [calendar.id, calendar])))
  const visibleCalendarIds = computed(() => new Set(calendars.value.filter((calendar) => calendar.visible).map((calendar) => calendar.id)))
  const today = computed(() => startOfWallDay(toWall(now.value, zone.value)))

  /** The days the current view covers. */
  const days = computed<CalendarDay[]>(() => {
    switch (view.value) {
      case 'day':
        return daysFrom(anchor.value, 1, zone.value)
      case 'week':
        return daysFrom(startOfWeek(anchor.value), 7, zone.value)
      case 'month': {
        // Whole weeks around the month: 5 or 6 rows.
        const first = startOfWeek(startOfMonth(anchor.value))
        const last = startOfWeek(addMonths(startOfMonth(anchor.value), 1) - 1) + 7 * 86_400_000
        return daysFrom(first, Math.round((last - first) / 86_400_000), zone.value)
      }
      case 'agenda':
        return daysFrom(anchor.value, AGENDA_DAYS, zone.value)
    }
    return []
  })

  /** Occurrences on visible calendars; cancelled ones and ones the user declined are left out. */
  const occurrences = computed(() =>
    loaded.value.filter((occurrence) => visibleCalendarIds.value.has(occurrence.event.calendarId) && occurrence.event.status !== 'cancelled' && occurrence.event.selfResponse !== 'declined'),
  )

  /** Invitations still waiting for the user's answer, from now on. */
  const unanswered = computed(() => occurrences.value.filter((occurrence) => occurrence.event.selfResponse === 'needs-action' && occurrence.end > now.value))

  let reading = 0
  async function load(): Promise<void> {
    const database = mail.currentStore()
    const range = days.value
    if (database === null || range.length === 0) {
      loaded.value = []
      return
    }
    const mine = ++reading
    loading.value = true
    try {
      const from = range[0]!.start
      const to = range[range.length - 1]!.end
      const events = await database.calendarEvents(from, to)
      if (mine !== reading) {
        return
      }
      rows.value = events
      loaded.value = expandOccurrences(events, from, to, zone.value)
    } finally {
      if (mine === reading) {
        loading.value = false
      }
    }
  }

  watch([days, () => mail.revision, () => mail.ready], () => void load(), { immediate: true })

  function show(kind: CalendarViewKind, day?: number): void {
    view.value = kind
    if (day !== undefined) {
      anchor.value = startOfWallDay(day)
    }
  }

  function goToday(): void {
    now.value = Date.now()
    anchor.value = today.value
  }

  /** The next (1) or previous (-1) day, week, month or agenda page. */
  function step(direction: 1 | -1): void {
    switch (view.value) {
      case 'day':
        anchor.value = addDays(anchor.value, direction)
        break
      case 'week':
        anchor.value = addDays(anchor.value, 7 * direction)
        break
      case 'month':
        anchor.value = addMonths(startOfMonth(anchor.value), direction)
        break
      case 'agenda':
        anchor.value = addDays(anchor.value, AGENDA_DAYS * direction)
        break
    }
  }

  function calendar(id: string): SyncedCalendar | undefined {
    return calendarsById.value.get(id)
  }

  /** Where new events go. */
  const newEventCalendar = computed(() => defaultCalendar(calendars.value))

  async function save(input: EventInput, target: { occurrence: Occurrence; scope: EditScope } | null, notify: boolean): Promise<SyncedCalendarEvent> {
    const request = saveRequest(input, target, notify)
    const saved = await auth.send<SyncedCalendarEvent>(request.method, request.path, request.body)
    await mail.syncNow()
    return saved
  }

  async function remove(occurrence: Occurrence, scope: EditScope, notify: boolean): Promise<void> {
    const request = deleteRequest(occurrence, scope, notify)
    await auth.send<void>(request.method, request.path)
    await mail.syncNow()
  }

  /** Answers an invitation, for the whole series or only this occurrence. */
  async function respond(occurrence: Pick<Occurrence, 'seriesId' | 'recurrenceId' | 'recurring'>, response: Response, onlyThis: boolean, comment: string | null = null, notify = true): Promise<void> {
    await respondTo(occurrence.seriesId, response, onlyThis && occurrence.recurring ? occurrence.recurrenceId : null, comment, notify)
  }

  async function respondTo(eventId: string, response: Response, recurrenceId: string | null, comment: string | null = null, notify = true): Promise<void> {
    await auth.send<SyncedCalendarEvent>('POST', `/api/v1/calendar-events/${encodeURIComponent(eventId)}/response`, { response, recurrenceId, comment, notify })
    await mail.syncNow()
  }

  async function setVisible(calendarId: string, visible: boolean): Promise<void> {
    await auth.send<SyncedCalendar>('PATCH', `/api/v1/calendars/${encodeURIComponent(calendarId)}`, { visible })
    await mail.syncNow()
  }

  function invitation(messageId: string): Promise<MessageInvitation> {
    return auth.api<MessageInvitation>(`/api/v1/messages/${encodeURIComponent(messageId)}/invitation`)
  }

  async function applyInvitation(messageId: string): Promise<MessageInvitation> {
    const applied = await auth.send<MessageInvitation>('POST', `/api/v1/messages/${encodeURIComponent(messageId)}/invitation/apply`)
    await mail.syncNow()
    return applied
  }

  /** The occurrences of the next days from now, for the mini agenda next to mail. */
  async function upcoming(daysAhead: number): Promise<Occurrence[]> {
    const database = mail.currentStore()
    if (database === null) {
      return []
    }
    const from = Date.now()
    const to = from + daysAhead * 86_400_000
    const events = await database.calendarEvents(from, to)
    return expandOccurrences(events, from, to, zone.value).filter(
      (occurrence) => visibleCalendarIds.value.has(occurrence.event.calendarId) && occurrence.event.status !== 'cancelled' && occurrence.event.selfResponse !== 'declined',
    )
  }

  return {
    zone,
    view,
    anchor,
    now,
    today,
    days,
    loading,
    rows,
    occurrences,
    unanswered,
    calendars,
    newEventCalendar,
    show,
    goToday,
    step,
    load,
    calendar,
    save,
    remove,
    respond,
    respondTo,
    setVisible,
    invitation,
    applyInvitation,
    upcoming,
  }
})
