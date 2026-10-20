import { beforeEach, describe, expect, it } from 'vitest'
import { startTimeZoneSync, storedSentZone } from '../timeZone'

const settle = () => new Promise((resolve) => setTimeout(resolve, 0))

function fakeApi() {
  const patches: unknown[] = []
  const api = async <T>(path: string, init: RequestInit = {}): Promise<T> => {
    expect(path).toBe('/api/v1/notification-settings')
    expect(init.method).toBe('PATCH')
    patches.push(JSON.parse(String(init.body)))
    return {} as T
  }
  return { api, patches }
}

// The browser's local storage, kept in memory.
function memoryStorage(): Storage {
  const items = new Map<string, string>()
  return {
    get length() {
      return items.size
    },
    clear: () => items.clear(),
    getItem: (key) => items.get(key) ?? null,
    key: (index) => [...items.keys()][index] ?? null,
    removeItem: (key) => void items.delete(key),
    setItem: (key, value) => void items.set(key, value),
  }
}

let storage: Storage

describe('startTimeZoneSync', () => {
  beforeEach(() => {
    storage = memoryStorage()
  })

  it('sends the zone once, not again after a reload, and again when the window comes back in another zone', async () => {
    const first = fakeApi()
    let zone = 'Europe/Amsterdam'
    const stop = startTimeZoneSync(first.api, storedSentZone('kim', storage), (error) => { throw error }, window, () => zone)
    await settle()
    expect(first.patches).toEqual([{ timeZone: 'Europe/Amsterdam' }])
    stop()

    // A reload: the zone this browser sent is remembered.
    const reloaded = fakeApi()
    const stopAgain = startTimeZoneSync(reloaded.api, storedSentZone('kim', storage), (error) => { throw error }, window, () => zone)
    await settle()
    expect(reloaded.patches).toEqual([])

    zone = 'America/New_York'
    window.dispatchEvent(new Event('focus'))
    await settle()
    expect(reloaded.patches).toEqual([{ timeZone: 'America/New_York' }])

    stopAgain()
    zone = 'Asia/Tokyo'
    window.dispatchEvent(new Event('focus'))
    await settle()
    expect(reloaded.patches).toHaveLength(1)
  })

  it('remembers the zone per user', async () => {
    const kim = fakeApi()
    const stopKim = startTimeZoneSync(kim.api, storedSentZone('kim', storage), (error) => { throw error }, window, () => 'Europe/Amsterdam')
    await settle()
    stopKim()
    const lisa = fakeApi()
    const stopLisa = startTimeZoneSync(lisa.api, storedSentZone('lisa', storage), (error) => { throw error }, window, () => 'Europe/Amsterdam')
    await settle()
    stopLisa()

    expect(lisa.patches).toEqual([{ timeZone: 'Europe/Amsterdam' }])
  })
})
