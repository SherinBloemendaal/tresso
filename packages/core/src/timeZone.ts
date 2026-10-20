/** Saves the zone reminders are planned in (PATCH /api/v1/notification-settings with `timeZone`). */
export type SaveTimeZone = (timeZone: string) => Promise<void>

/**
 * Where a device keeps the zone it last sent for a user, so a reload or a restart does not send it again (and so
 * does not take the zone back from another device the user moved on to).
 */
export interface SentZone {
  get(): Promise<string | null>
  set(zone: string): Promise<void>
}

/** The device's IANA time zone, or null when the runtime does not tell. */
export function deviceTimeZone(): string | null {
  const zone = Intl.DateTimeFormat().resolvedOptions().timeZone
  return typeof zone === 'string' && zone !== '' ? zone : null
}

/**
 * Keeps the zone reminders are planned in at the device's: sent when the device's zone differs from the one this
 * device last sent (the first time, or after a trip or a changed system zone), checked now and whenever `onWake`
 * calls back (the app or window coming to the front). A zone another device sent since is left alone until this
 * device's own zone changes. A failure is reported and tried again on the next wake. Returns the function that stops
 * it.
 */
export function keepTimeZone(
  save: SaveTimeZone,
  sent: SentZone,
  onWake: (check: () => void) => () => void,
  onError: (error: unknown) => void,
  zone: () => string | null = deviceTimeZone,
): () => void {
  let busy = false
  let stopped = false
  const check = (): void => {
    const current = zone()
    if (stopped || busy || current === null) {
      return
    }
    busy = true
    sent
      .get()
      .then(async (last) => {
        if (last !== current && !stopped) {
          await save(current)
          await sent.set(current)
        }
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
