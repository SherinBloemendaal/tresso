import { describe, expect, it } from 'vitest'
import { keepTimeZone, type SentZone } from './timeZone'

const settle = () => new Promise((resolve) => setTimeout(resolve, 0))

function memory(initial: string | null = null): SentZone & { value: string | null } {
  const sent = {
    value: initial,
    async get() {
      return sent.value
    },
    async set(zone: string) {
      sent.value = zone
    },
  }
  return sent
}

function wakeable() {
  let wake = (): void => undefined
  let unsubscribed = false
  return {
    onWake: (check: () => void) => ((wake = check), () => (unsubscribed = true)),
    wake: () => wake(),
    unsubscribed: () => unsubscribed,
  }
}

describe('keepTimeZone', () => {
  it('sends the zone the first time and again only when the device zone changed', async () => {
    const saved: string[] = []
    const sent = memory()
    const device = wakeable()
    let zone = 'Europe/Amsterdam'
    keepTimeZone(async (z) => void saved.push(z), sent, device.onWake, () => undefined, () => zone)
    await settle()
    expect(saved).toEqual(['Europe/Amsterdam'])

    device.wake()
    await settle()
    expect(saved).toEqual(['Europe/Amsterdam'])

    zone = 'America/New_York'
    device.wake()
    await settle()
    expect(saved).toEqual(['Europe/Amsterdam', 'America/New_York'])
    expect(sent.value).toBe('America/New_York')
  })

  it('does not send again after a reload when the device zone is the one it sent', async () => {
    const saved: string[] = []
    keepTimeZone(async (z) => void saved.push(z), memory('Europe/Amsterdam'), wakeable().onWake, () => undefined, () => 'Europe/Amsterdam')
    await settle()

    expect(saved).toEqual([])
  })

  it('reports a failure, remembers nothing and tries again on the next wake', async () => {
    let failures = 1
    const saved: string[] = []
    const errors: unknown[] = []
    const sent = memory()
    const device = wakeable()
    const save = async (z: string) => {
      if (failures-- > 0) throw new Error('offline')
      saved.push(z)
    }
    keepTimeZone(save, sent, device.onWake, (error) => errors.push(error), () => 'Asia/Tokyo')
    await settle()
    expect(errors).toHaveLength(1)
    expect(sent.value).toBeNull()

    device.wake()
    await settle()
    expect(saved).toEqual(['Asia/Tokyo'])
  })

  it('stops listening when stopped', async () => {
    const saved: string[] = []
    const device = wakeable()
    let zone = 'Europe/Amsterdam'
    const stop = keepTimeZone(async (z) => void saved.push(z), memory(), device.onWake, () => undefined, () => zone)
    await settle()
    stop()

    zone = 'Asia/Tokyo'
    device.wake()
    await settle()
    expect(device.unsubscribed()).toBe(true)
    expect(saved).toEqual(['Europe/Amsterdam'])
  })
})
