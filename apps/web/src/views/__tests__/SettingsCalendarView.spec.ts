import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { SchedulingPreferences } from '@tresso/core'
import SettingsCalendarView from '../SettingsCalendarView.vue'

/** Form validation runs asynchronously; let it and the requests it starts finish. */
async function settle(): Promise<void> {
  for (let i = 0; i < 5; i++) {
    await flushPromises()
    await new Promise((resolve) => setTimeout(resolve, 0))
  }
}

const stored: SchedulingPreferences = { bufferMinutes: 10, travelMinutes: 0, minimumNoticeMinutes: 0, planTodos: false }

describe('SettingsCalendarView', () => {
  const requests: { method: string; path: string; body: unknown }[] = []

  beforeEach(() => {
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    requests.length = 0
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const method = init?.method ?? 'GET'
        const body = init?.body ? (JSON.parse(String(init.body)) as Partial<SchedulingPreferences>) : undefined
        requests.push({ method, path: new URL(String(input)).pathname, body })
        return new Response(JSON.stringify({ ...stored, ...body }))
      }),
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    sessionStorage.clear()
  })

  it('switches todo planning on and sends only that', async () => {
    const wrapper = mount(SettingsCalendarView)
    await settle()
    const toggle = wrapper.get('input[type="checkbox"]')
    expect((toggle.element as HTMLInputElement).checked).toBe(false)
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined()

    await toggle.setValue(true)
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(requests).toEqual([
      { method: 'GET', path: '/api/v1/calendar/scheduling-preferences', body: undefined },
      { method: 'PATCH', path: '/api/v1/calendar/scheduling-preferences', body: { planTodos: true } },
    ])
    expect(wrapper.get('.saved').text()).toBe('Saved. Your open todos with a due date and a duration are being planned.')
  })
})
