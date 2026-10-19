import { deviceTimeZone, keepTimeZone, type NotificationSettings } from '@tresso/core'

type Api = <T>(path: string, init?: RequestInit) => Promise<T>

/**
 * Keeps the zone reminders are planned in at this browser's (or desktop app's): sent once signed in, and checked again
 * whenever the window comes back to the front. Returns the function that stops it.
 */
export function startTimeZoneSync(api: Api, onError: (error: unknown) => void, target: Window = window, zone: () => string | null = deviceTimeZone): () => void {
  return keepTimeZone(
    {
      read: () => api<NotificationSettings>('/api/v1/notification-settings'),
      save: async (timeZone) => {
        await api<NotificationSettings>('/api/v1/notification-settings', {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/merge-patch+json' },
          body: JSON.stringify({ timeZone }),
        })
      },
    },
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
