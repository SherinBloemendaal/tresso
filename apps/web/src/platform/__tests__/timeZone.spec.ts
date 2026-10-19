import { describe, expect, it } from 'vitest'
import { startTimeZoneSync } from '../timeZone'

const settle = () => new Promise((resolve) => setTimeout(resolve, 0))

function fakeApi(timeZone: string) {
  const calls: { path: string; method: string; body: unknown }[] = []
  const api = async <T>(path: string, init: RequestInit = {}): Promise<T> => {
    const method = init.method ?? 'GET'
    const body = typeof init.body === 'string' ? JSON.parse(init.body) : undefined
    calls.push({ path, method, body })
    if (method === 'PATCH') {
      timeZone = (body as { timeZone: string }).timeZone
    }
    return { timeZone } as T
  }
  return { api, calls }
}

describe('startTimeZoneSync', () => {
  it('sends the device zone once signed in and again when the window comes back in another zone', async () => {
    const { api, calls } = fakeApi('UTC')
    let zone = 'Europe/Amsterdam'
    const stop = startTimeZoneSync(api, (error) => { throw error }, window, () => zone)
    await settle()
    expect(calls.filter((call) => call.method === 'PATCH').map((call) => call.body)).toEqual([{ timeZone: 'Europe/Amsterdam' }])

    zone = 'America/New_York'
    window.dispatchEvent(new Event('focus'))
    await settle()
    expect(calls.filter((call) => call.method === 'PATCH').map((call) => call.body)).toEqual([{ timeZone: 'Europe/Amsterdam' }, { timeZone: 'America/New_York' }])
    expect(calls.every((call) => call.path === '/api/v1/notification-settings')).toBe(true)

    stop()
    zone = 'Asia/Tokyo'
    window.dispatchEvent(new Event('focus'))
    await settle()
    expect(calls.filter((call) => call.method === 'PATCH')).toHaveLength(2)
  })
})
