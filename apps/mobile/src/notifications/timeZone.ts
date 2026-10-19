import { deviceTimeZone, keepTimeZone, type NotificationSettings } from '@tresso/core'

type Api = <T>(path: string, init?: RequestInit) => Promise<T>

/** Calls back when the app comes to the front (React Native's AppState in the app). */
export interface AppStateLike {
  addEventListener(type: 'change', listener: (state: string) => void): { remove(): void }
}

/**
 * Keeps the zone reminders are planned in at this phone's: sent once signed in, and checked again whenever the app
 * comes back to the front (after a flight, or a changed system zone). Returns the function that stops it.
 */
export function startTimeZoneSync(api: Api, appState: AppStateLike, onError: (error: unknown) => void, zone: () => string | null = deviceTimeZone): () => void {
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
      const subscription = appState.addEventListener('change', (state) => {
        if (state === 'active') {
          check()
        }
      })
      return () => subscription.remove()
    },
    onError,
    zone,
  )
}
