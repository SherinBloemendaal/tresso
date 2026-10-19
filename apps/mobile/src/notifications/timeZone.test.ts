import { describe, expect, it } from 'vitest'
import { startTimeZoneSync } from './timeZone'

const settle = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('startTimeZoneSync', () => {
  it('sends the phone zone once signed in and again when the app comes back in another zone', async () => {
    let serverZone = 'UTC'
    const patches: unknown[] = []
    const api = async <T>(_path: string, init: RequestInit = {}): Promise<T> => {
      if (init.method === 'PATCH') {
        const body = JSON.parse(String(init.body)) as { timeZone: string }
        patches.push(body)
        serverZone = body.timeZone
      }
      return { timeZone: serverZone } as T
    }
    const listeners: ((state: string) => void)[] = []
    const appState = { addEventListener: (_type: 'change', listener: (state: string) => void) => (listeners.push(listener), { remove: () => listeners.splice(listeners.indexOf(listener), 1) }) }
    let zone = 'Europe/Amsterdam'

    const stop = startTimeZoneSync(api, appState, (error) => { throw error }, () => zone)
    await settle()
    expect(patches).toEqual([{ timeZone: 'Europe/Amsterdam' }])

    zone = 'Asia/Tokyo'
    listeners.forEach((listener) => listener('background'))
    await settle()
    expect(patches).toHaveLength(1)
    listeners.forEach((listener) => listener('active'))
    await settle()
    expect(patches).toEqual([{ timeZone: 'Europe/Amsterdam' }, { timeZone: 'Asia/Tokyo' }])

    stop()
    expect(listeners).toHaveLength(0)
  })
})
