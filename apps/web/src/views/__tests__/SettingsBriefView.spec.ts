import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { DailyBriefSettings } from '@tresso/core'
import SettingsBriefView from '../SettingsBriefView.vue'

/** Form validation runs asynchronously; let it and the requests it starts finish. */
async function settle(): Promise<void> {
  for (let i = 0; i < 5; i++) {
    await flushPromises()
    await new Promise((resolve) => setTimeout(resolve, 0))
  }
}

const stored: DailyBriefSettings = {
  enabled: true,
  deliverAt: '08:00',
  timeZone: 'Europe/Amsterdam',
  channels: [],
  emailAddress: 'kim@company.example',
  nextBriefAt: '2026-10-21T06:00:00+00:00',
}

describe('SettingsBriefView', () => {
  const requests: { method: string; body: unknown }[] = []

  beforeEach(() => {
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    requests.length = 0
    vi.stubGlobal(
      'fetch',
      vi.fn(async (_input: RequestInfo | URL, init?: RequestInit) => {
        const method = init?.method ?? 'GET'
        const body = init?.body ? (JSON.parse(String(init.body)) as Partial<DailyBriefSettings>) : undefined
        requests.push({ method, body })
        if (method === 'GET') {
          return new Response(JSON.stringify(stored))
        }
        if (body?.deliverAt === '23:59') {
          return new Response(JSON.stringify({ detail: 'deliverAt: no', violations: [{ propertyPath: 'deliverAt', message: 'Use a time of day as HH:MM.' }] }), { status: 422 })
        }
        return new Response(JSON.stringify({ ...stored, ...body }))
      }),
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    sessionStorage.clear()
  })

  async function mountView() {
    const wrapper = mount(SettingsBriefView)
    await settle()
    return wrapper
  }

  it('marks the required fields from the schema', async () => {
    const wrapper = await mountView()

    const required = wrapper.findAll('.field-label').filter((label) => label.find('.required').exists())
    expect(required.map((label) => label.text().replace(/\s*\*\s*\(required\)$/, ''))).toEqual(['Time'])
  })

  it('shows the stored settings, the zone they are read in and when the next brief comes', async () => {
    const wrapper = await mountView()

    expect((wrapper.get('input[type="time"]').element as HTMLInputElement).value).toBe('08:00')
    expect(wrapper.text()).toContain('In your time zone, Europe/Amsterdam.')
    expect(wrapper.text()).toContain('Goes to kim@company.example')
    expect(wrapper.text()).toContain('The next brief comes')
  })

  it('saves a new time and the channels besides the app', async () => {
    const wrapper = await mountView()

    await wrapper.get('input[type="time"]').setValue('07:30')
    await wrapper.get('input[value="phone"]').setValue(true)
    await wrapper.get('input[value="email"]').setValue(true)
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(requests[requests.length - 1]).toEqual({ method: 'PUT', body: { enabled: true, deliverAt: '07:30', channels: ['phone', 'email'] } })
    expect(wrapper.text()).toContain('Saved.')
  })

  it('refuses a time that is no time before asking the server', async () => {
    const wrapper = await mountView()

    await wrapper.get('input[type="time"]').setValue('')
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(requests.filter((request) => request.method === 'PUT')).toEqual([])
    expect(wrapper.text()).toContain('Pick a time of day.')
  })

  it('shows what the server refused next to the field', async () => {
    const wrapper = await mountView()

    await wrapper.get('input[type="time"]').setValue('23:59')
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(wrapper.text()).toContain('Use a time of day as HH:MM.')
  })
})
