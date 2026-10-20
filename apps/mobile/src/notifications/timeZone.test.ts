import { beforeEach, describe, expect, it, vi } from 'vitest'

const items = vi.hoisted(() => new Map<string, string>())

vi.mock('expo-secure-store', () => ({
  getItemAsync: async (key: string) => items.get(key) ?? null,
  setItemAsync: async (key: string, value: string) => {
    items.set(key, value)
  },
}))

const { startTimeZoneSync, storedSentZone } = await import('./timeZone')

const settle = () => new Promise((resolve) => setTimeout(resolve, 0))

function fakeApi() {
  const patches: unknown[] = []
  const api = async <T>(_path: string, init: RequestInit = {}): Promise<T> => {
    patches.push(JSON.parse(String(init.body)))
    return {} as T
  }
  return { api, patches }
}

function fakeAppState() {
  const listeners: ((state: string) => void)[] = []
  return {
    listeners,
    addEventListener: (_type: 'change', listener: (state: string) => void) => (listeners.push(listener), { remove: () => listeners.splice(listeners.indexOf(listener), 1) }),
    emit: (state: string) => listeners.forEach((listener) => listener(state)),
  }
}

describe('startTimeZoneSync', () => {
  beforeEach(() => items.clear())

  it('sends the zone once, not again after a restart, and again when the app comes back in another zone', async () => {
    const first = fakeApi()
    let zone = 'Europe/Amsterdam'
    const stopFirst = startTimeZoneSync(first.api, storedSentZone('kim'), fakeAppState(), (error) => { throw error }, () => zone)
    await settle()
    expect(first.patches).toEqual([{ timeZone: 'Europe/Amsterdam' }])
    stopFirst()

    const restarted = fakeApi()
    const appState = fakeAppState()
    const stop = startTimeZoneSync(restarted.api, storedSentZone('kim'), appState, (error) => { throw error }, () => zone)
    await settle()
    expect(restarted.patches).toEqual([])

    zone = 'Asia/Tokyo'
    appState.emit('background')
    await settle()
    expect(restarted.patches).toEqual([])
    appState.emit('active')
    await settle()
    expect(restarted.patches).toEqual([{ timeZone: 'Asia/Tokyo' }])

    stop()
    expect(appState.listeners).toHaveLength(0)
  })
})
