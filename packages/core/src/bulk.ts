/**
 * Wire format of /api/v1/bulk-actions, /api/v1/bulk-selection, /api/v1/smart-inboxes/{id}/senders and
 * /api/v1/cleanup-suggestions, and the words clients use for them.
 */

/** Which conversations a bulk action looks at: what a smart inbox lists, what the inboxes list, or the whole mailbox. */
export type BulkArea = 'smartInbox' | 'inbox' | 'mailbox'

/** What a bulk action does to every conversation of its scope. */
export type BulkAction = 'markRead' | 'archive' | 'trash' | 'move'

/** The conversations a bulk action takes; every filter narrows them down. */
export interface BulkScope {
  in: BulkArea
  /** Required exactly for `in: 'smartInbox'`. */
  smartInboxId?: string | null
  /** Only this account's; required for a move, which stays within one account. */
  mailAccountId?: string | null
  /** Only conversations with a message from this address. */
  sender?: string | null
  /** Only conversations whose newest message is older than this many days. */
  olderThanDays?: number | null
  /** Only conversations with a message of at least this many bytes. */
  largerThan?: number | null
}

export interface BulkActionRequest extends BulkScope {
  action: BulkAction
  /** The folder to move to; only for `move`. */
  folderId?: string
}

export type BulkActionStatus = 'running' | 'done' | 'failed' | 'undoing' | 'undone'

/** A bulk action as the server reports it while and after it works through its batches. */
export interface BulkActionState {
  id: string
  action: BulkAction
  folderId: string | null
  /** null once the smart inbox it was about is deleted. */
  scope: {
    in: BulkArea
    smartInboxId: string | null
    mailAccountId: string | null
    sender: string | null
    /** ISO time: conversations whose newest message is older than this. */
    before: string | null
    largerThan: number | null
  } | null
  status: BulkActionStatus
  /** Conversations worked through so far. */
  conversations: number
  /** Messages that changed; the others already were that way. */
  changed: number
  /** Changed messages an undo cannot put back exactly (they were in several folders, or archived into All Mail). */
  unrestorable: number
  /** Messages the undo put back. */
  restored: number
  canUndo: boolean
  error: string | null
  createdAt: string
  finishedAt: string | null
}

/** How much a bulk action would take, counted before it starts. */
export interface BulkSelectionCount {
  conversations: number
  messages: number
  mailAccountIds: string[]
}

export interface SenderStatistic {
  address: string
  name: string | null
  conversations: number
  messages: number
  unread: number
  lastReceivedAt: string
}

/** What a smart inbox lists, in numbers, and its senders with the most conversations first. */
export interface SmartInboxSenders {
  id: string
  conversations: number
  messages: number
  unread: number
  senders: SenderStatistic[]
}

export type CleanupSuggestionKind = 'newsletter' | 'unopenedSender' | 'oldInSmartInbox' | 'largeOld'

/** What a fixed rule found to clean up, with the bulk action that does it: send `scope` and `action` as they are. */
export interface CleanupSuggestion {
  id: string
  kind: CleanupSuggestionKind
  conversations: number
  unread: number | null
  bytes: number | null
  sender: string | null
  senderName: string | null
  smartInboxId: string | null
  /** The days the rule looked back to see whether any of the sender's mail was opened. */
  windowDays: number | null
  /** How many messages the sender sent in those days, none of which was opened. */
  windowReceived: number | null
  olderThanDays: number | null
  largerThan: number | null
  action: BulkAction
  scope: Required<Pick<BulkScope, 'in' | 'smartInboxId' | 'sender' | 'olderThanDays' | 'largerThan'>>
}

/** `GET /api/v1/cleanup-suggestions`: senders first, then old mail in smart inboxes, then large mail. */
export interface CleanupSuggestionList {
  suggestions: CleanupSuggestion[]
}

/** The ages the "older than" choices offer, in days. */
export const BULK_AGES: readonly { days: number; label: string }[] = [
  { days: 7, label: 'a week' },
  { days: 30, label: 'a month' },
  { days: 90, label: '3 months' },
  { days: 365, label: 'a year' },
]

/** The query of `GET /api/v1/bulk-selection` for a scope. */
export function bulkSelectionQuery(scope: BulkScope): string {
  const query = new URLSearchParams({ in: scope.in })
  for (const key of ['smartInboxId', 'mailAccountId', 'sender', 'olderThanDays', 'largerThan'] as const) {
    const value = scope[key]
    if (value !== null && value !== undefined) {
      query.set(key, String(value))
    }
  }

  return query.toString()
}

/** Whether the server is still working through it (the action or its undo). */
export function bulkActionRunning(state: Pick<BulkActionState, 'status'>): boolean {
  return state.status === 'running' || state.status === 'undoing'
}

/** How long to wait before asking again how a running bulk action is doing: soon at first, then less often. */
export function bulkPollDelay(attempt: number): number {
  return Math.min(500 * 2 ** attempt, 4000)
}

export function conversationCount(count: number): string {
  return count === 1 ? 'one conversation' : `${count.toLocaleString('en')} conversations`
}

function messageCount(count: number): string {
  return count === 1 ? 'one message' : `${count.toLocaleString('en')} messages`
}

/** The button for an action, e.g. "Archive 12 conversations". */
export function bulkActionLabel(action: BulkAction, conversations: number, folderName?: string): string {
  const what = conversationCount(conversations)
  switch (action) {
    case 'markRead':
      return `Mark ${what} read`
    case 'archive':
      return `Archive ${what}`
    case 'trash':
      return `Move ${what} to Trash`
    case 'move':
      return `Move ${what} to ${folderName ?? 'the folder'}`
  }
}

/** What to tell the user while an action runs, e.g. "Archiving: 400 conversations done". */
export function bulkProgressText(state: Pick<BulkActionState, 'action' | 'status' | 'conversations' | 'restored'>): string {
  if (state.status === 'undoing') {
    return `Putting back: ${messageCount(state.restored)} so far`
  }
  const verb = { markRead: 'Marking read', archive: 'Archiving', trash: 'Moving to Trash', move: 'Moving' }[state.action]

  return `${verb}: ${conversationCount(state.conversations)} done`
}

/** What to tell the user once an action or its undo stopped, with what an undo cannot put back. */
export function bulkResultText(state: Pick<BulkActionState, 'action' | 'status' | 'conversations' | 'changed' | 'unrestorable' | 'restored' | 'error'>, folderName?: string): string {
  if (state.status === 'undone') {
    return state.restored === 0 ? 'Nothing needed putting back.' : `Put back ${messageCount(state.restored)}.`
  }
  const done = { markRead: 'Marked read', archive: 'Archived', trash: 'Moved to Trash', move: `Moved to ${folderName ?? 'the folder'}` }[state.action]
  const what = state.conversations === 0 ? 'Nothing to do: no conversations matched.' : `${done}: ${conversationCount(state.conversations)}.`
  if (state.status === 'failed') {
    return `Stopped after ${conversationCount(state.conversations)}: ${state.error ?? 'something went wrong'}`
  }
  const caveat = state.unrestorable === 0 || state.changed === state.unrestorable ? '' : ` Undo cannot put ${messageCount(state.unrestorable)} back.`

  return state.conversations === 0 ? what : `${what}${caveat}`
}

/** Bytes in the unit people read, e.g. "5 MB". */
export function formatBytes(bytes: number): string {
  const units = ['bytes', 'KB', 'MB', 'GB', 'TB']
  let value = bytes
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit++
  }

  return unit === 0 ? `${bytes} bytes` : `${value >= 10 ? Math.round(value) : Math.round(value * 10) / 10} ${units[unit]}`
}

/** The share of a sender's messages that was read, as a whole percentage. */
export function readRate(sender: Pick<SenderStatistic, 'messages' | 'unread'>): number {
  return sender.messages === 0 ? 0 : Math.round(((sender.messages - sender.unread) / sender.messages) * 100)
}

/**
 * A suggestion in words: what was found and the rule that found it, so the user can see why it is suggested.
 *
 * @param smartInboxName the name of the suggestion's smart inbox, for `oldInSmartInbox`
 */
export function cleanupSuggestionText(suggestion: CleanupSuggestion, smartInboxName?: string): { title: string; detail: string; button: string } {
  const sender = suggestion.senderName ?? suggestion.sender ?? 'this sender'
  const unread = suggestion.unread ?? 0
  const window = suggestion.windowDays ?? 0
  const sent = suggestion.windowReceived ?? 0
  const unopened = `${unread.toLocaleString('en')} unread in your inbox, and you opened none of the ${sent === 1 ? 'one message' : `${sent.toLocaleString('en')} messages`} they sent in the last ${window} days.`
  const age = BULK_AGES.find((choice) => choice.days === suggestion.olderThanDays)?.label ?? `${suggestion.olderThanDays ?? 0} days`
  const button = suggestion.action === 'trash' ? 'Move to Trash' : 'Archive'
  switch (suggestion.kind) {
    case 'newsletter':
      return {
        title: `Newsletters from ${sender}`,
        detail: unopened,
        button: `Archive ${conversationCount(suggestion.conversations)}`,
      }
    case 'unopenedSender':
      return {
        title: `Mail from ${sender}`,
        detail: unopened,
        button: `Archive ${conversationCount(suggestion.conversations)}`,
      }
    case 'oldInSmartInbox':
      return {
        title: `${smartInboxName ?? 'Smart inbox'} older than ${age}`,
        detail: `${conversationCount(suggestion.conversations)} in ${smartInboxName ?? 'this smart inbox'} had no new mail for over ${age}.`,
        button: `${button} ${conversationCount(suggestion.conversations)}`,
      }
    case 'largeOld':
      return {
        title: `Large mail older than ${age}`,
        detail: `${conversationCount(suggestion.conversations)} with a message of ${formatBytes(suggestion.largerThan ?? 0)} or more, ${formatBytes(suggestion.bytes ?? 0)} in all, and no new mail for over ${age}.`,
        button: `${button}`,
      }
  }
}
