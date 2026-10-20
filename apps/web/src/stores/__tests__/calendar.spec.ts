import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises } from '@vue/test-utils'
import { parseWall, type MailStore, type SyncedCalendarEvent } from '@tresso/core'
import { calendar as calendarRow, calendarEvent } from '@tresso/core/testing'
import { useCalendarStore } from '@/stores/calendar'
import { useMailStore } from '@/stores/mail'

const AMS = 'Europe/Amsterdam'

const weekly = calendarEvent('weekly', 'work', {
  uid: 'weekly@tresso.test',
  start: { dateTime: '2026-10-05T09:00:00', timeZone: AMS },
  end: { dateTime: '2026-10-05T09:30:00', timeZone: AMS },
  recurrence: 'FREQ=WEEKLY;BYDAY=MO,TH',
})
const declined = calendarEvent('declined', 'work', { selfResponse: 'declined' })
const invited = calendarEvent('invited', 'work', { selfResponse: 'needs-action', start: { dateTime: '2026-10-22T16:00:00', timeZone: AMS }, end: { dateTime: '2026-10-22T17:00:00', timeZone: AMS } })
const hidden = calendarEvent('hidden', 'personal')

describe('calendar store', () => {
  const requests: { method: string; path: string; body: unknown }[] = []
  let events: SyncedCalendarEvent[]

  beforeEach(() => {
    vi.useFakeTimers({ now: Date.UTC(2026, 9, 20, 8, 24), toFake: ['Date'] })
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    requests.length = 0
    events = [weekly, declined, invited, hidden]
    const mail = useMailStore()
    mail.calendars = [calendarRow('work', 'work', { name: 'Work' }), calendarRow('personal', 'home', { name: 'Personal', visible: false })]
    vi.spyOn(mail, 'currentStore').mockReturnValue({ calendarEvents: async () => events } as unknown as MailStore)
    vi.spyOn(mail, 'syncNow').mockResolvedValue()
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = new URL(String(input))
        requests.push({ method: init?.method ?? 'GET', path: `${url.pathname}${url.search}`, body: init?.body ? JSON.parse(String(init.body)) : undefined })
        return init?.method === 'DELETE' ? new Response(null, { status: 204 }) : new Response(JSON.stringify(weekly), { status: 200 })
      }),
    )
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
    sessionStorage.clear()
  })

  async function shown(): Promise<ReturnType<typeof useCalendarStore>> {
    const store = useCalendarStore()
    store.zone = AMS
    store.show('week', parseWall('2026-10-20')!)
    await nextTick()
    await store.load()
    await flushPromises()
    return store
  }

  it('expands the week on the device, without hidden calendars or declined events', async () => {
    const store = await shown()
    expect(store.days.map((day) => day.wall)).toEqual([19, 20, 21, 22, 23, 24, 25].map((day) => Date.UTC(2026, 9, day)))
    expect(store.occurrences.map((o) => o.recurrenceId)).toEqual(['20261019T070000Z', '20261022T070000Z', null])
    expect(store.occurrences.map((o) => o.event.id)).toEqual(['weekly', 'weekly', 'invited'])
    expect(store.unanswered.map((o) => o.event.id)).toEqual(['invited'])
  })

  it('steps by the view and comes back to today', async () => {
    const store = await shown()
    store.step(1)
    expect(store.anchor).toBe(Date.UTC(2026, 9, 27))
    store.show('month')
    store.step(-1)
    expect(store.anchor).toBe(Date.UTC(2026, 8, 1))
    expect(store.days[0]!.wall).toBe(Date.UTC(2026, 7, 31))
    store.goToday()
    expect(store.anchor).toBe(Date.UTC(2026, 9, 20))
  })

  it('saves an occurrence through its series with the scope, and answers for one occurrence', async () => {
    const store = await shown()
    const thursday = store.occurrences.find((o) => o.recurrenceId === '20261022T070000Z')!
    await store.save({ ...thursdayInput(), summary: 'Moved' }, { occurrence: thursday, scope: 'following' }, false)
    await store.respond(thursday, 'tentative', true)
    await store.remove(thursday, 'this', true)
    expect(requests.map((r) => `${r.method} ${r.path}`)).toEqual([
      'PUT /api/v1/calendar-events/weekly?notify=false&scope=following&recurrenceId=20261022T070000Z',
      'POST /api/v1/calendar-events/weekly/response',
      'DELETE /api/v1/calendar-events/weekly?scope=this&recurrenceId=20261022T070000Z',
    ])
    expect(requests[1]!.body).toEqual({ response: 'tentative', recurrenceId: '20261022T070000Z', comment: null, notify: true })
  })

  it('shows and hides a calendar for the user through the API', async () => {
    const store = await shown()
    await store.setVisible('personal', true)
    expect(requests[0]).toEqual({ method: 'PATCH', path: '/api/v1/calendars/personal', body: { visible: true } })
  })
})

function thursdayInput() {
  return {
    calendarId: 'work',
    summary: 'Weekly',
    description: '',
    location: '',
    url: null,
    start: { dateTime: '2026-10-22T09:00:00', timeZone: AMS },
    end: { dateTime: '2026-10-22T09:30:00', timeZone: AMS },
    recurrence: 'FREQ=WEEKLY;BYDAY=MO,TH',
    status: 'confirmed' as const,
    transparency: 'busy' as const,
    visibility: 'public' as const,
    attendees: [],
    useDefaultReminders: true,
    reminders: [],
    important: false,
    travelMinutes: null,
    conferenceUrl: null,
    organizerMailAccountId: null,
  }
}
