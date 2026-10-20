import { firstName, mailNotificationTarget, type SyncedHandOver, type SyncedMailNotification } from '@tresso/core'

/** More notifications than this at once become one summary instead of a stack of them. */
const MAX_SEPARATE = 3
/**
 * Notifications delivered longer ago than this before notifications were switched on are old news reaching the device
 * late (a copy rebuilt, a device that was offline), not news.
 */
export const FRESHNESS_MS = 15 * 60_000

export interface MailNotification {
  title: string
  body: string
  /** Where a click leads: the conversation, or null to open the app as it is. */
  path: string | null
  /** Delivered in quiet hours or while the user was busy: no sound. */
  silent: boolean
}

/**
 * The delivered mail notifications this desktop should show: sent to the desktop, recent enough given when
 * notifications were switched on, and not shown before.
 */
export function forTheDesktop(notifications: SyncedMailNotification[], notifyingSince: number, shown: Set<string>): SyncedMailNotification[] {
  return notifications.filter(
    (notification) =>
      notification.channels.includes('desktop') && !shown.has(notification.id) && Date.parse(notification.deliveredAt) >= notifyingSince - FRESHNESS_MS,
  )
}

/** What to show: one notification each, or a summary when much arrives at once. */
export function mailNotifications(notifications: SyncedMailNotification[]): MailNotification[] {
  if (notifications.length <= MAX_SEPARATE) {
    return notifications.map((notification) => ({
      title: notification.title,
      body: notification.body,
      path: mailNotificationTarget(notification),
      silent: notification.silent,
    }))
  }
  const senders = [...new Set(notifications.map((notification) => notification.title))]
  const named = senders.slice(0, MAX_SEPARATE).join(', ')
  return [
    {
      title: `${notifications.length} new messages need you`,
      body: senders.length > MAX_SEPARATE ? `From ${named} and ${senders.length - MAX_SEPARATE} more` : `From ${named}`,
      path: null,
      // Quiet only when every one of them came quietly.
      silent: notifications.every((notification) => notification.silent),
    },
  ]
}

/** What to show for hand-overs whose due date passed without a reply: one each, or a summary for many at once. */
export function overdueNotifications(handOvers: SyncedHandOver[]): MailNotification[] {
  if (handOvers.length <= MAX_SEPARATE) {
    return handOvers.map((handOver) => ({
      title: `No reply from ${firstName(handOver.delegate)} yet`,
      body: `${handOver.subject || '(no subject)'} is past its due date. Nudge from Waiting on.`,
      path: `/waiting?nudge=${encodeURIComponent(handOver.id)}`,
      silent: handOver.notifiedSilently,
    }))
  }
  return [
    {
      title: `${handOvers.length} hand-overs are past their due date`,
      body: 'Nudge from Waiting on.',
      path: '/waiting',
      silent: handOvers.every((handOver) => handOver.notifiedSilently),
    },
  ]
}
