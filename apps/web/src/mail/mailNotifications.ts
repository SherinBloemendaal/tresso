import type { Arrival } from '@/mail/db'

/** More arrivals than this at once become one summary instead of a stack of notifications. */
const MAX_SEPARATE = 3
/**
 * Arrivals received longer ago than this before notifications were switched on are old mail reaching the device
 * late (an account's history being fetched, a device that was offline), not news.
 */
export const FRESHNESS_MS = 15 * 60_000

export interface MailNotification {
  title: string
  body: string
}

function sender(arrival: Arrival): string {
  return arrival.from.name?.trim() || arrival.from.address
}

function line(arrival: Arrival): string {
  return arrival.verdict === 'delegate' ? `To hand over: ${arrival.subject || '(no subject)'}` : arrival.subject || '(no subject)'
}

/** Arrivals that are recent enough to notify about, given when notifications were switched on. */
export function freshArrivals(arrivals: Arrival[], notifyingSince: number): Arrival[] {
  return arrivals.filter((arrival) => Date.parse(arrival.receivedAt) >= notifyingSince - FRESHNESS_MS)
}

/** What to show for new mail that needs the user: one notification each, or a summary when much arrives at once. */
export function mailNotifications(arrivals: Arrival[]): MailNotification[] {
  if (arrivals.length <= MAX_SEPARATE) {
    return arrivals.map((arrival) => ({
      title: sender(arrival),
      body: arrival.snippet === '' ? line(arrival) : `${line(arrival)}\n${arrival.snippet}`,
    }))
  }
  const senders = [...new Set(arrivals.map(sender))]
  const named = senders.slice(0, MAX_SEPARATE).join(', ')
  return [
    {
      title: `${arrivals.length} new messages need you`,
      body: senders.length > MAX_SEPARATE ? `From ${named} and ${senders.length - MAX_SEPARATE} more` : `From ${named}`,
    },
  ]
}
