import { beforeEach, describe, expect, it, vi } from 'vitest'

const created = vi.hoisted(() => new Map<string, Record<string, unknown>>())

vi.mock('expo-notifications', () => ({
  AndroidImportance: { LOW: 2, DEFAULT: 3, HIGH: 4 },
  setNotificationChannelAsync: async (id: string, channel: Record<string, unknown>) => {
    created.set(id, channel)
    return { id, ...channel }
  },
}))

const { createReminderChannels } = await import('./channels')

describe('createReminderChannels', () => {
  beforeEach(() => created.clear())

  it('creates the channels the API sends reminders on: a banner with sound, and a silent one', async () => {
    await createReminderChannels('android')

    expect([...created.keys()]).toEqual(['reminders', 'reminders_silent'])
    expect(created.get('reminders')).toMatchObject({ importance: 4, sound: 'default' })
    expect(created.get('reminders_silent')).toMatchObject({ importance: 2, sound: null, enableVibrate: false })
  })

  it('creates none on iOS', async () => {
    await createReminderChannels('ios')

    expect(created.size).toBe(0)
  })
})
