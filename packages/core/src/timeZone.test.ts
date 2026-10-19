import { describe, expect, it } from 'vitest'
import { keepTimeZone, syncTimeZone, type TimeZoneSettings } from './timeZone'

function fakeSettings(timeZone: string, failSaves = 0): TimeZoneSettings & { saved: string[]; reads: number } {
  let failures = failSaves
  const settings = {
    saved: [] as string[],
    reads: 0,
    timeZone,
    async read() {
      settings.reads++
      return { timeZone: settings.timeZone }
    },
    async save(zone: string) {
      if (failures > 0) {
        failures--
        throw new Error('offline')
      }
      settings.saved.push(zone)
      settings.timeZone = zone
    },
  }
  return settings
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('syncTimeZone', () => {
  it('saves the device zone when the settings have another one', async () => {
    const settings = fakeSettings('UTC')

    expect(await syncTimeZone(settings, 'Europe/Amsterdam')).toBe(true)
    expect(settings.saved).toEqual(['Europe/Amsterdam'])
  })

  it('leaves the settings alone when they have the zone already', async () => {
    const settings = fakeSettings('Europe/Amsterdam')

    expect(await syncTimeZone(settings, 'Europe/Amsterdam')).toBe(false)
    expect(settings.saved).toEqual([])
  })
})

describe('keepTimeZone', () => {
  it('sends the zone at start and again only when the device zone changed', async () => {
    const settings = fakeSettings('UTC')
    let zone = 'Europe/Amsterdam'
    let wake = (): void => undefined
    keepTimeZone(settings, (check) => ((wake = check), () => undefined), () => undefined, () => zone)
    await settle()
    expect(settings.saved).toEqual(['Europe/Amsterdam'])

    wake()
    await settle()
    expect(settings.reads).toBe(1)

    zone = 'America/New_York'
    wake()
    await settle()
    expect(settings.saved).toEqual(['Europe/Amsterdam', 'America/New_York'])
  })

  it('reports a failure and tries again on the next wake', async () => {
    const settings = fakeSettings('UTC', 1)
    const errors: unknown[] = []
    let wake = (): void => undefined
    keepTimeZone(settings, (check) => ((wake = check), () => undefined), (error) => errors.push(error), () => 'Asia/Tokyo')
    await settle()
    expect(errors).toHaveLength(1)

    wake()
    await settle()
    expect(settings.saved).toEqual(['Asia/Tokyo'])
  })

  it('stops listening when stopped', async () => {
    const settings = fakeSettings('UTC')
    let unsubscribed = false
    let wake = (): void => undefined
    let zone = 'Europe/Amsterdam'
    const stop = keepTimeZone(settings, (check) => ((wake = check), () => (unsubscribed = true)), () => undefined, () => zone)
    await settle()
    stop()

    zone = 'Asia/Tokyo'
    wake()
    await settle()
    expect(unsubscribed).toBe(true)
    expect(settings.saved).toEqual(['Europe/Amsterdam'])
  })
})
