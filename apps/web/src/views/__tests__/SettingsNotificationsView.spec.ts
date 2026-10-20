import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import SettingsNotificationsView from '../SettingsNotificationsView.vue'

/** Form validation runs asynchronously; let it and the requests it starts finish. */
async function settle(): Promise<void> {
  for (let i = 0; i < 5; i++) {
    await flushPromises()
    await new Promise((resolve) => setTimeout(resolve, 0))
  }
}

describe('SettingsNotificationsView', () => {
  const requests: { method: string; path: string; body: unknown }[] = []
  let settings: Record<string, unknown>

  beforeEach(() => {
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    requests.length = 0
    settings = { channelsByKind: { brief: ['email'] }, holdMailInMeetings: true, mailPreviews: true }
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = new URL(String(input))
        const method = init?.method ?? 'GET'
        const body = init?.body ? (JSON.parse(String(init.body)) as Record<string, unknown>) : undefined
        requests.push({ method, path: url.pathname, body })
        if (method === 'PATCH' && body !== undefined) {
          const channelsByKind = { ...(settings.channelsByKind as object), ...(body.channelsByKind as object) }
          settings = { ...settings, ...body, channelsByKind }
        }
        return new Response(JSON.stringify(settings))
      }),
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
    sessionStorage.clear()
  })

  it('shows the default channels for mail and saves the user’s choice for mail only', async () => {
    const wrapper = mount(SettingsNotificationsView)
    await settle()

    const boxes = wrapper.findAll('fieldset input[type="checkbox"]')
    expect(boxes.map((box) => (box.element as HTMLInputElement).checked)).toEqual([true, true, false])

    await boxes[1]!.setValue(false)
    await boxes[2]!.setValue(true)
    await wrapper.get('form').trigger('submit')
    await settle()

    const patch = requests.find((request) => request.method === 'PATCH')
    expect(patch).toEqual({ method: 'PATCH', path: '/api/v1/notification-settings', body: { channelsByKind: { mail: ['phone', 'email'] }, holdMailInMeetings: true, mailPreviews: true } })
    expect(wrapper.get('[role="status"]').text()).toBe('Saved.')
    expect(settings.channelsByKind).toEqual({ brief: ['email'], mail: ['phone', 'email'] })
  })

  it('says so when no channel is left', async () => {
    const wrapper = mount(SettingsNotificationsView)
    await settle()

    for (const box of wrapper.findAll('fieldset input[type="checkbox"]').slice(0, 2)) {
      await box.setValue(false)
    }
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(wrapper.get('[role="status"]').text()).toBe('Saved. New mail no longer notifies you.')
  })

  it('turns message content off in notifications', async () => {
    const wrapper = mount(SettingsNotificationsView)
    await settle()

    const previews = wrapper.findAll('input[type="checkbox"]').find((box) => box.element.closest('label')?.textContent?.includes('Show message content'))
    expect((previews!.element as HTMLInputElement).checked).toBe(true)
    await previews!.setValue(false)
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(requests.find((request) => request.method === 'PATCH')?.body).toMatchObject({ mailPreviews: false })
    expect(settings.mailPreviews).toBe(false)
  })
})
