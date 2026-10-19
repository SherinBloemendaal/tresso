import type { NotificationSettings } from './notifications'

/** The user's notification settings as a client reads and writes them (GET and PATCH /api/v1/notification-settings). */
export interface TimeZoneSettings {
  read(): Promise<Pick<NotificationSettings, 'timeZone'>>
  save(timeZone: string): Promise<void>
}

/** The device's IANA time zone, or null when the runtime does not tell. */
export function deviceTimeZone(): string | null {
  const zone = Intl.DateTimeFormat().resolvedOptions().timeZone
  return typeof zone === 'string' && zone !== '' ? zone : null
}

/** Saves the zone as the one reminders are planned in, unless the settings have it already; true when it saved. */
export async function syncTimeZone(settings: TimeZoneSettings, zone: string): Promise<boolean> {
  const current = await settings.read()
  if (current.timeZone === zone) {
    return false
  }
  await settings.save(zone)
  return true
}

/**
 * Keeps the zone reminders are planned in at the device's: checked now, and again whenever `onWake` calls back (the
 * app or window coming to the front, which is when a trip or a changed system zone shows). The API is asked only when
 * the device's zone differs from the last one that was sent; a failure is reported and tried again on the next wake.
 * Returns the function that stops it.
 */
export function keepTimeZone(
  settings: TimeZoneSettings,
  onWake: (check: () => void) => () => void,
  onError: (error: unknown) => void,
  zone: () => string | null = deviceTimeZone,
): () => void {
  let sent: string | null = null
  let busy = false
  let stopped = false
  const check = (): void => {
    const current = zone()
    if (stopped || busy || current === null || current === sent) {
      return
    }
    busy = true
    syncTimeZone(settings, current)
      .then(() => {
        sent = current
      })
      .catch(onError)
      .finally(() => {
        busy = false
      })
  }
  check()
  const unsubscribe = onWake(check)
  return () => {
    stopped = true
    unsubscribe()
  }
}
