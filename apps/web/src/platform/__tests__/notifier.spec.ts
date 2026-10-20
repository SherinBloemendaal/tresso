import { beforeEach, describe, expect, it, vi } from 'vitest'

const sent = vi.hoisted(() => [] as Record<string, unknown>[])
const listening = vi.hoisted(() => ({ fails: false }))

vi.mock('@/platform/desktop', () => ({ isDesktop: true }))
vi.mock('@tauri-apps/api/window', () => ({ getCurrentWindow: () => ({ unminimize: async () => {}, setFocus: async () => {} }) }))
vi.mock('@tauri-apps/plugin-notification', () => ({
  isPermissionGranted: async () => true,
  requestPermission: async () => 'granted',
  onAction: async () => {
    if (listening.fails) {
      throw new Error('not allowed')
    }
    return { unregister: async () => {} }
  },
  sendNotification: (options: Record<string, unknown>) => sent.push(options),
}))

const { clickedPath, desktopSound, notifier, startNotificationClicks } = await import('../notifier')

describe('clickedPath', () => {
  it('opens the path a desktop notification carries, only within the app', () => {
    expect(clickedPath({ path: '/thread/t1' })).toBe('/thread/t1')
    expect(clickedPath({ path: null })).toBeNull()
    expect(clickedPath({ path: 'https://elsewhere.example/' })).toBeNull()
    expect(clickedPath(undefined)).toBeNull()
  })
})

describe('desktop notifications', () => {
  beforeEach(() => {
    sent.length = 0
  })

  it('plays a system sound, except for notifications that came silently', async () => {
    await notifier!.show([
      { title: 'Anna', body: 'Budget review', path: '/thread/t1', silent: false },
      { title: 'Bob', body: 'Offsite', path: '/thread/t2', silent: true },
    ])

    expect(sent).toEqual([
      { title: 'Anna', body: 'Budget review', extra: { path: '/thread/t1' }, sound: desktopSound(navigator.userAgent) },
      { title: 'Bob', body: 'Offsite', extra: { path: '/thread/t2' } },
    ])
  })

  it('names a sound each platform has', () => {
    expect(desktopSound('Mozilla/5.0 (Macintosh; Intel Mac OS X 15_6) AppleWebKit/605.1.15')).toBe('Ping')
    expect(desktopSound('Mozilla/5.0 (Windows NT 10.0; Win64; x64)')).toBe('Mail')
    expect(desktopSound('Mozilla/5.0 (X11; Linux x86_64)')).toBe('message-new-email')
  })
})

describe('startNotificationClicks', () => {
  it('holds nothing up and reports a listener that cannot be registered', async () => {
    listening.fails = true
    const report = vi.fn<(error: unknown) => void>()

    expect(startNotificationClicks(() => {}, report)).toBeUndefined()

    await vi.waitFor(() => expect(report).toHaveBeenCalledWith(new Error('not allowed')))
    listening.fails = false
  })
})
