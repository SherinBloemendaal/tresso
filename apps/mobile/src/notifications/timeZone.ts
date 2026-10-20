import * as SecureStore from 'expo-secure-store'
import { deviceTimeZone, keepTimeZone, type NotificationSettings, type SentZone } from '@tresso/core'

type Api = <T>(path: string, init?: RequestInit) => Promise<T>

/** Calls back when the app comes to the front (React Native's AppState in the app). */
export interface AppStateLike {
  addEventListener(type: 'change', listener: (state: string) => void): { remove(): void }
}

/** The zone this phone last sent for the user, kept in the secure store. */
export function storedSentZone(userId: string): SentZone {
  const key = `timeZone.${userId}`
  return {
    get: () => SecureStore.getItemAsync(key),
    set: (zone) => SecureStore.setItemAsync(key, zone),
  }
}

/**
 * Keeps the zone reminders are planned in at this phone's: sent when it differs from the zone this phone last sent
 * for the user, checked once signed in and whenever the app comes back to the front (after a flight, or a changed
 * system zone). Returns the function that stops it.
 */
export function startTimeZoneSync(api: Api, sent: SentZone, appState: AppStateLike, onError: (error: unknown) => void, zone: () => string | null = deviceTimeZone): () => void {
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
