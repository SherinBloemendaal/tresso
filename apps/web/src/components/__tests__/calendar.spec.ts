import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { daysFrom, expandOccurrences, type SyncedCalendarEvent } from '@tresso/core'
import { calendar as calendarRow, calendarEvent } from '@tresso/core/testing'
import { useCalendarStore } from '@/stores/calendar'
import { useMailStore } from '@/stores/mail'
import EventEditor from '../calendar/EventEditor.vue'
import MonthGrid from '../calendar/MonthGrid.vue'
import TimeGrid from '../calendar/TimeGrid.vue'
import InvitationCard from '../InvitationCard.vue'

const AMS = 'Europe/Amsterdam'
const now = Date.UTC(2026, 9, 20, 8, 24)
const work = calendarRow('work', 'work', { name: 'Work', isDefault: true })

function timed(id: string, start: string, end: string, extra: Partial<SyncedCalendarEvent> = {}): SyncedCalendarEvent {
  return calendarEvent(id, 'work', { summary: id, start: { dateTime: start, timeZone: AMS }, end: { dateTime: end, timeZone: AMS }, ...extra })
}

function router() {
  return createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }, { path: '/calendar/:view?/:date?', name: 'calendar', component: { template: '<div />' } }] })
}

describe('time grid', () => {
  const days = daysFrom(Date.UTC(2026, 9, 19), 7, AMS)
  const events = [
    timed('Standup', '2026-10-19T09:00:00', '2026-10-19T09:15:00'),
    timed('Review', '2026-10-19T10:00:00', '2026-10-19T11:00:00', { selfResponse: 'needs-action' }),
    timed('Lunch', '2026-10-21T12:30:00', '2026-10-21T13:30:00', { location: 'Café Loos' }),
    calendarEvent('Offsite', 'work', { summary: 'Offsite', allDay: true, start: { date: '2026-10-22' }, end: { date: '2026-10-24' } }),
  ]
  const occurrences = expandOccurrences(events, days[0]!.start, days[6]!.end, AMS)

  function grid() {
    return mount(TimeGrid, { props: { days, occurrences, zone: AMS, now, today: Date.UTC(2026, 9, 20), calendars: () => work, selectedKey: null }, attachTo: document.body })
  }

  it('puts timed events on the grid and all-day ones in the all-day row, each with a full label', () => {
    const wrapper = grid()
    const blocks = wrapper.findAll('.block')
    expect(blocks.map((block) => block.find('.title').text())).toEqual(['Standup', 'Review', 'Lunch'])
    expect(blocks[0]!.attributes('style')).toContain('top: 540px')
    expect(blocks[1]!.classes()).toContain('pending')
    expect(blocks[2]!.attributes('aria-label')).toBe('Lunch, Wednesday 21 October, 12:30 to 13:30, Work, Café Loos')
    const span = wrapper.get('.span')
    expect(span.text()).toBe('Offsite')
    expect(span.attributes('style')).toContain('grid-column: 4 / 6')
    wrapper.unmount()
  })

  it('has one block in the tab order and moves with the arrow keys', async () => {
    const wrapper = grid()
    const tabbable = wrapper.findAll('[data-occurrence]').filter((item) => item.attributes('tabindex') === '0')
    expect(tabbable).toHaveLength(1)
    const standup = wrapper.findAll('.block')[0]!
    ;(standup.element as HTMLElement).focus()
    await standup.trigger('keydown', { key: 'ArrowDown' })
    expect(document.activeElement?.textContent).toContain('Review')
    await wrapper.findAll('.block')[1]!.trigger('keydown', { key: 'ArrowRight' })
    expect(document.activeElement?.textContent).toContain('Lunch')
    wrapper.unmount()
  })

  it('opens an event and starts a new one where the user clicks', async () => {
    const wrapper = grid()
    await wrapper.findAll('.block')[2]!.trigger('click')
    expect(wrapper.emitted('open')?.[0]?.[0]).toMatchObject({ event: { id: 'Lunch' } })
    wrapper.unmount()
  })
})

describe('month grid', () => {
  it('moves between days with the arrow keys and opens the day with Enter', async () => {
    const days = daysFrom(Date.UTC(2026, 8, 28), 35, AMS)
    const wrapper = mount(MonthGrid, {
      props: { days, month: Date.UTC(2026, 9, 1), occurrences: [], zone: AMS, now, today: Date.UTC(2026, 9, 20), calendars: () => work, selectedKey: null },
      attachTo: document.body,
    })
    const today = wrapper.get(`[data-day="${Date.UTC(2026, 9, 20)}"]`)
    expect(today.attributes('tabindex')).toBe('0')
    expect(today.attributes('aria-current')).toBe('date')
    ;(today.element as HTMLElement).focus()
    await today.trigger('keydown', { key: 'ArrowDown' })
    await flushPromises()
    expect((document.activeElement as HTMLElement).dataset.day).toBe(String(Date.UTC(2026, 9, 27)))
    await wrapper.get(`[data-day="${Date.UTC(2026, 9, 27)}"]`).trigger('keydown', { key: 'Enter' })
    expect(wrapper.emitted('showDay')?.[0]).toEqual([Date.UTC(2026, 9, 27)])
    wrapper.unmount()
  })
})

describe('editor and invitation card', () => {
  const requests: { method: string; path: string; body: unknown }[] = []
  let answer: (method: string, path: string) => unknown

  beforeEach(() => {
    vi.useFakeTimers({ now, toFake: ['Date'] })
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    requests.length = 0
    answer = () => ({})
    const mail = useMailStore()
    mail.calendars = [work]
    vi.spyOn(mail, 'syncNow').mockResolvedValue()
    useCalendarStore().zone = AMS
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = new URL(String(input))
        const method = init?.method ?? 'GET'
        requests.push({ method, path: `${url.pathname}${url.search}`, body: init?.body ? JSON.parse(String(init.body)) : undefined })
        return new Response(JSON.stringify(answer(method, url.pathname)), { status: 200 })
      }),
    )
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
    sessionStorage.clear()
  })

  it('creates an event from the form', async () => {
    const wrapper = mount(EventEditor, { props: { target: { kind: 'new', start: Date.UTC(2026, 9, 22, 8), allDay: false } }, global: { plugins: [router()] } })
    await wrapper.get('input[placeholder="Add a title"]').setValue('Budget walkthrough')
    await wrapper.get('input[placeholder="Add a place"]').setValue('Room 3.2')
    await wrapper.get('form').trigger('submit')
    await vi.waitFor(() => expect(wrapper.emitted('saved')).toHaveLength(1))
    expect(requests).toHaveLength(1)
    expect(requests[0]).toMatchObject({
      method: 'POST',
      path: '/api/v1/calendar-events',
      body: { calendarId: 'work', summary: 'Budget walkthrough', location: 'Room 3.2', start: { dateTime: '2026-10-22T10:00:00', timeZone: AMS }, end: { dateTime: '2026-10-22T11:00:00', timeZone: AMS }, recurrence: null },
    })
    wrapper.unmount()
  })

  it('asks which events of a series a change is for, and sends that scope', async () => {
    const series = timed('Weekly', '2026-10-05T09:00:00', '2026-10-05T09:30:00', { recurrence: 'FREQ=WEEKLY;BYDAY=TH' })
    const thursday = expandOccurrences([series], Date.UTC(2026, 9, 19), Date.UTC(2026, 9, 26), AMS)[0]!
    const wrapper = mount(EventEditor, { props: { target: { kind: 'edit', occurrence: thursday } }, global: { plugins: [router()] } })
    const scopes = wrapper.findAll('.scope input[type="radio"]')
    expect(scopes.map((input) => (input.element as HTMLInputElement).value)).toEqual(['this', 'following', 'all'])
    await scopes[1]!.setValue(true)
    expect(wrapper.get('.scope .hint').text()).toContain('every later one change')
    await wrapper.get('form').trigger('submit')
    await vi.waitFor(() => expect(wrapper.emitted('saved')).toHaveLength(1))
    expect(requests[0]?.path).toBe('/api/v1/calendar-events/Weekly?scope=following&recurrenceId=20261022T070000Z')
    expect(requests[0]?.body).toMatchObject({ start: { dateTime: '2026-10-22T09:00:00', timeZone: AMS }, recurrence: 'FREQ=WEEKLY;BYDAY=TH' })
    wrapper.unmount()
  })

  it('shows what is wrong before saving', async () => {
    const wrapper = mount(EventEditor, { props: { target: { kind: 'new', start: Date.UTC(2026, 9, 22, 8), allDay: false } }, global: { plugins: [router()] } })
    const times = wrapper.findAll('input[type="time"]')
    await times[1]!.setValue('09:00')
    await wrapper.get('form').trigger('submit')
    await vi.waitFor(() => expect(wrapper.text()).toContain('The event ends before it starts.'))
    expect(requests).toHaveLength(0)
    wrapper.unmount()
  })

  it('shows an invitation with its conflicts and answers it with a key', async () => {
    const invitation = {
      id: 'm1',
      method: 'REQUEST',
      kind: 'update',
      uid: 'review@company.example',
      recurrenceId: '20261023T080000Z',
      sequence: 2,
      summary: 'Product review',
      description: '',
      location: 'Room 3.2',
      allDay: false,
      start: { dateTime: '2026-10-22T10:00:00', timeZone: AMS },
      end: { dateTime: '2026-10-22T11:00:00', timeZone: AMS },
      recurrence: null,
      organizer: { address: 'sanne@company.example', name: 'Sanne Peters' },
      attendees: [
        { address: 'kim@company.example', name: 'Kim', role: 'required', partstat: 'needs-action', rsvp: true, kind: 'individual' },
        { address: 'mark@company.example', name: 'Mark', role: 'required', partstat: 'accepted', rsvp: true, kind: 'individual' },
      ],
      conference: { provider: 'teams', url: 'https://teams.example.com/join', id: null, dialIn: null },
      eventId: 'event-1',
      selfResponse: 'needs-action',
      canRespond: true,
      conflicts: [{ id: 'c1', eventId: 'e2', summary: 'Vendor call', allDay: false, start: { dateTime: '2026-10-22T08:30:00+00:00' }, end: { dateTime: '2026-10-22T09:00:00+00:00' }, selfResponse: 'tentative' }],
      canApply: false,
    }
    answer = (method) => (method === 'GET' ? invitation : {})
    const wrapper = mount(InvitationCard, { props: { messageId: 'm1' }, global: { plugins: [router()] } })
    await flushPromises()
    expect(wrapper.text()).toContain('Updated invitation')
    expect(wrapper.text()).toContain('Thursday 22 October, 10:00 to 11:00')
    expect(wrapper.text()).toContain('Overlaps Vendor call, 10:30 to 11:00 (maybe)')
    expect(wrapper.text()).toContain('Join Teams meeting')
    expect(wrapper.text()).toContain('2 people: 1 going, 1 no reply')
    await wrapper.get('.invitation').trigger('keydown', { key: 'y' })
    await flushPromises()
    expect(requests[1]).toEqual({ method: 'POST', path: '/api/v1/calendar-events/event-1/response', body: { response: 'accepted', recurrenceId: '20261023T080000Z', comment: null, notify: true } })
    expect(wrapper.text()).toContain('You answered: Going')
  })

  it('offers to apply calendar mail the calendar did not take', async () => {
    const pending = { id: 'm2', method: 'REQUEST', kind: 'new', uid: 'x', recurrenceId: null, sequence: 0, summary: 'Old invite', description: '', location: '', allDay: true, start: { date: '2026-10-22' }, end: { date: '2026-10-23' }, recurrence: null, organizer: null, attendees: [], conference: null, eventId: null, selfResponse: null, canRespond: false, conflicts: [], canApply: true }
    answer = (method) => (method === 'GET' ? pending : { ...pending, canApply: false, eventId: 'new-event', canRespond: true, selfResponse: 'needs-action' })
    const wrapper = mount(InvitationCard, { props: { messageId: 'm2' }, global: { plugins: [router()] } })
    await flushPromises()
    await wrapper.get('button.button-primary').trigger('click')
    await flushPromises()
    expect(requests[1]).toMatchObject({ method: 'POST', path: '/api/v1/messages/m2/invitation/apply' })
    expect(wrapper.text()).toContain('Going?')
  })
})
