import { deviceTimeZone, keepTimeZone, type NotificationSettings, type SentZone } from '@tresso/core'

type Api = <T>(path: string, init?: RequestInit) => Promise<T>

/** The zone this browser (or desktop app) last sent for the user, kept in local storage. */
export function storedSentZone(userId: string, storage: Storage = localStorage): SentZone {
  const key = `tresso.timeZone.${userId}`
  return {
    get: async () => storage.getItem(key),
    set: async (zone) => storage.setItem(key, zone),
  }
}

/**
 * Keeps the zone reminders are planned in at this browser's (or desktop app's): sent when it differs from the zone
 * this device last sent for the user, checked once signed in and whenever the window comes back to the front.
 * Returns the function that stops it.
 */
export function startTimeZoneSync(api: Api, sent: SentZone, onError: (error: unknown) => void, target: Window = window, zone: () => string | null = deviceTimeZone): () => void {
  return keepTimeZone(
    async (timeZone) => {
      await api<NotificationSettings>('/api/v1/notification-settings', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/merge-patch+json' },
        body: JSON.stringify({ timeZone }),
      })
    },
    sent,
    (check) => {
      const whenVisible = (): void => {
        if (target.document.visibilityState === 'visible') {
          check()
        }
      }
      target.addEventListener('focus', check)
      target.document.addEventListener('visibilitychange', whenVisible)
      return () => {
        target.removeEventListener('focus', check)
        target.document.removeEventListener('visibilitychange', whenVisible)
      }
    },
    onError,
    zone,
  )
}
