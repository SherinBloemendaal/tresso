import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { batchTimeLabel, type BatchSchedule } from '@tresso/core'
import SettingsBatchesView from '../SettingsBatchesView.vue'

const BROWSER_ZONE = Intl.DateTimeFormat().resolvedOptions().timeZone
/** A zone other than the browser's, so a test can tell which one the form took. */
const SAVED_ZONE = BROWSER_ZONE === 'Pacific/Chatham' ? 'Europe/Amsterdam' : 'Pacific/Chatham'
const NEXT = '2026-10-20T14:00:00Z'

/** Form validation runs asynchronously; let it and the requests it starts finish. */
async function settle(): Promise<void> {
  for (let i = 0; i < 5; i++) {
    await flushPromises()
    await new Promise((resolve) => setTimeout(resolve, 0))
  }
}

describe('SettingsBatchesView', () => {
  const writes: { method: string; body: unknown }[] = []
  let schedule: BatchSchedule
  let refuse: boolean

  beforeEach(() => {
    sessionStorage.setItem('auth.tokens', JSON.stringify({ accessToken: 'token', refreshToken: null, expiresAt: Date.now() + 3_600_000 }))
    setActivePinia(createPinia())
    writes.length = 0
    refuse = false
    schedule = { times: ['09:00', '16:00'], timeZone: SAVED_ZONE, configured: true, nextBatchAt: '2026-10-20T09:00:00Z' }
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = new URL(String(input))
        if (url.pathname !== '/api/v1/batch-delivery') {
          return new Response('{}', { status: 404 })
        }
        if ((init?.method ?? 'GET') === 'GET') {
          return new Response(JSON.stringify(schedule))
        }
        const body = JSON.parse(String(init?.body)) as { times: string[]; timeZone: string }
        writes.push({ method: init?.method ?? 'GET', body })
        if (refuse) {
          return new Response(JSON.stringify({ detail: 'timeZone: Use a known time zone.', violations: [{ propertyPath: 'timeZone', message: 'Use a known time zone.' }] }), { status: 422 })
        }
        return new Response(JSON.stringify({ ...body, configured: true, nextBatchAt: NEXT }))
      }),
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    sessionStorage.clear()
  })

  async function mountView() {
    const wrapper = mount(SettingsBatchesView)
    await settle()
    return wrapper
  }

  function timeInputs(wrapper: Awaited<ReturnType<typeof mountView>>) {
    return wrapper.findAll<HTMLInputElement>('input[type="time"]')
  }

  it('shows the saved times and zone, and marks the required fields from the schema', async () => {
    const wrapper = await mountView()

    expect(timeInputs(wrapper).map((input) => input.element.value)).toEqual(['09:00', '16:00'])
    expect(wrapper.get<HTMLSelectElement>('#batch-time-zone').element.value).toBe(SAVED_ZONE)
    const required = wrapper.findAll('.field-label').filter((label) => label.find('.required').exists())
    expect(required.map((label) => label.text().replace(/\s*\*\s*\(required\)$/, ''))).toEqual(['Batch times', 'Time zone'])
  })

  it('offers the browser\'s time zone while the user has not picked one', async () => {
    schedule = { ...schedule, configured: false, timeZone: SAVED_ZONE }
    const wrapper = await mountView()

    expect(wrapper.get<HTMLSelectElement>('#batch-time-zone').element.value).toBe(BROWSER_ZONE)
    expect(wrapper.text()).toContain('This is the time zone of this device.')

    await wrapper.get('form').trigger('submit')
    await settle()

    expect(writes).toEqual([{ method: 'PUT', body: { times: ['09:00', '16:00'], timeZone: BROWSER_ZONE } }])
  })

  it('needs at least one time', async () => {
    const wrapper = await mountView()

    await wrapper.get('button[aria-label="Remove batch time 2"]').trigger('click')
    await wrapper.get('button[aria-label="Remove batch time 1"]').trigger('click')
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(writes).toEqual([])
    expect(wrapper.text()).toContain('Add at least one batch time.')
  })

  it('refuses a time twice before asking the server', async () => {
    const wrapper = await mountView()

    await timeInputs(wrapper)[1]!.setValue('09:00')
    await wrapper.get('form').trigger('submit')
    await settle()

    expect(writes).toEqual([])
    expect(wrapper.text()).toContain('09:00 is already a batch time.')
    expect(timeInputs(wrapper)[1]!.attributes('aria-invalid')).toBe('true')
  })

  it('adds up to eight times and saves them in the order of the day, with the next batch', async () => {
    const wrapper = await mountView()

    await wrapper.get('button[aria-label="Remove batch time 1"]').trigger('click')
    const add = wrapper.findAll('button').find((button) => button.text() === '+ Add a batch time')!
    await add.trigger('click')
    expect(timeInputs(wrapper).map((input) => input.element.value)).toEqual(['16:00', '08:00'])
    for (let i = 0; i < 6; i++) {
      await add.trigger('click')
    }
    expect(timeInputs(wrapper)).toHaveLength(8)
    expect(add.attributes('disabled')).toBeDefined()

    await wrapper.get('form').trigger('submit')
    await settle()

    expect(writes).toEqual([
      { method: 'PUT', body: { times: ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '16:00'], timeZone: SAVED_ZONE } },
    ])
    expect(wrapper.get('[role="status"]').text()).toBe(`Saved. Next batch at ${batchTimeLabel(Date.parse(NEXT))}.`)
  })

  it('shows what the server refused on the field', async () => {
    const wrapper = await mountView()
    refuse = true

    await wrapper.get('form').trigger('submit')
    await settle()

    expect(wrapper.get('#batch-time-zone').attributes('aria-invalid')).toBe('true')
    expect(wrapper.text()).toContain('Use a known time zone.')
  })
})
