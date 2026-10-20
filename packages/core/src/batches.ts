/** Wire format of /api/v1/batch-delivery and /api/v1/unsubscriptions, and what clients show of them. */

import type { SyncedMessage, SyncedUnsubscription, UnsubscribeMethod } from './sync/types'

/** When the user's batches go out. */
export interface BatchSchedule {
  /** Times of day as "HH:MM", sorted. */
  times: string[]
  /** An IANA time zone. */
  timeZone: string
  /** False while the user has the defaults, so a client can offer its own time zone. */
  configured: boolean
  nextBatchAt: string
}

export interface BatchScheduleRequest {
  times: string[]
  timeZone: string
}

/** At most this many batches a day; mirrors App\Batch\BatchTimes::MAX_TIMES. */
export const MAX_BATCH_TIMES = 8

/** "HH:MM", 00:00 to 23:59; mirrors App\Batch\BatchTimes::TIME_PATTERN. */
export const BATCH_TIME_PATTERN = /^([01][0-9]|2[0-3]):[0-5][0-9]$/

export interface UnsubscribeRequest {
  messageId: string
}

/** The answer to POST /api/v1/unsubscriptions: the record the delta brings as well. */
export type Unsubscription = SyncedUnsubscription

/** What a conversation offers: the message to unsubscribe from and how, or why the button is gone. */
export type UnsubscribeOffer =
  | {
      state: 'available'
      messageId: string
      mailAccountId: string
      sender: string
      method: UnsubscribeMethod
      /** For `mailto`: where the unsubscribe mail goes, to show before it is sent. */
      recipient: string | null
      error: string | null
    }
  | { state: 'pending' | 'done'; sender: string }

/**
 * Whether a conversation offers one-click unsubscribe: its newest message that has a one-click or mailto address, for
 * that message's account and sender. Gone while a request for them is pending or once one is done; offered again
 * (with the reason) after a failure. Null when no message offers it.
 */
export function unsubscribeOffer(messages: SyncedMessage[], unsubscriptions: SyncedUnsubscription[]): UnsubscribeOffer | null {
  const newestFirst = [...messages].sort((a, b) => Date.parse(b.receivedAt) - Date.parse(a.receivedAt))
  const message = newestFirst.find((m) => m.unsubscribe)
  if (!message?.unsubscribe) {
    return null
  }
  const sender = message.from.address.trim().toLowerCase()
  const known = unsubscriptions.find((u) => u.mailAccountId === message.mailAccountId && u.sender === sender)
  if (known && known.status !== 'failed') {
    return { state: known.status, sender }
  }
  const recipient = message.unsubscribe === 'mailto' ? (message.unsubscribeMailto ?? null) : null
  return { state: 'available', messageId: message.id, mailAccountId: message.mailAccountId, sender, method: message.unsubscribe, recipient, error: known?.error ?? null }
}

/** A batch time in the reader's words, e.g. "16:00", or "Tue 09:00" when it is not today. */
export function batchTimeLabel(at: number, now: number = Date.now(), locale?: string, timeZone?: string): string {
  const time = new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit', hour12: false, timeZone }).format(at)
  const day = (instant: number) => new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone }).format(instant)
  if (day(at) === day(now)) {
    return time
  }
  return `${new Intl.DateTimeFormat(locale, { weekday: 'short', timeZone }).format(at)} ${time}`
}
