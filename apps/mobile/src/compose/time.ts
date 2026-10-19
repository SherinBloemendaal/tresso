/** A time a message can still be sent at: at least a minute ahead. */
export function isFutureTime(at: Date, now: number = Date.now()): boolean {
  return at.getTime() >= now + 60_000
}
