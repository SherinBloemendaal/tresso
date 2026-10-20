import { handOverStatus, nudgeStands, type SyncedHandOver, type SyncedOutgoingMessage } from '@tresso/core'

/** A due date as the forward and follow-ups write it: "Friday 24 October", with the year when it is another one. */
export function dueLabel(iso: string, now = new Date()): string {
  const date = new Date(iso)
  return date.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', ...(date.getFullYear() === now.getFullYear() ? {} : { year: 'numeric' }) })
}

/** A due date in a list: "Fri 24 Oct". */
export function shortDue(iso: string, now = new Date()): string {
  const date = new Date(iso)
  return date.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', ...(date.getFullYear() === now.getFullYear() ? {} : { year: 'numeric' }) })
}

/** How a hand-over stands, in words: overdue, nudged, waiting, replied or done. */
export function statusText(handOver: SyncedHandOver, nudge: SyncedOutgoingMessage | null, now = new Date()): string {
  const status = handOverStatus(handOver, now)
  if (status === 'waiting' && handOver.nudgedAt !== null && nudgeStands(handOver, nudge)) {
    return `Nudged ${shortDue(handOver.nudgedAt, now)}`
  }
  return { overdue: 'Overdue', waiting: 'Waiting', replied: 'Replied', done: 'Done' }[status]
}
