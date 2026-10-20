import type { NotificationChannel } from './notifications'
import type { ConversationSummary } from './sync/conversation'
import type { Address, SyncedDailyBrief, SyncedSmartInbox } from './sync/types'

/** GET and PUT /api/v1/daily-brief-settings */
export interface DailyBriefSettings {
  enabled: boolean
  /** HH:MM in timeZone. */
  deliverAt: string
  /** The user's notification time zone, which devices keep current. Read only. */
  timeZone: string
  /** Where the brief goes besides the app, which always shows it: the notification channels of kind `daily_brief`. */
  channels: NotificationChannel[]
  /** Where the email digest goes: the notification address. Read only. */
  emailAddress: string
  /** When the next brief is made; null while the brief is off. Read only. */
  nextBriefAt: string | null
}

export type DailyBriefSettingsUpdate = Pick<DailyBriefSettings, 'enabled' | 'deliverAt' | 'channels'>

/** A conversation of a daily brief as it is now. */
export interface BriefEntry {
  conversation: ConversationSummary
  /** Noted: read and archived, so it left the inboxes since the brief was made. */
  noted: boolean
}

/** A brief with the conversations of it this device still has, newest first. */
export interface BriefView {
  brief: SyncedDailyBrief
  entries: BriefEntry[]
}

/** How a brief is grouped on screen: per smart inbox (the default) or per sender. */
export type BriefGrouping = 'smartInbox' | 'sender'

export interface BriefGroup {
  /** The smart inbox id, or the sender's lower-case address. */
  key: string
  label: string
  entries: BriefEntry[]
}

/** The brief's conversations that still exist, in the brief's order; one is noted when the inboxes no longer list it. */
export function briefView(brief: SyncedDailyBrief, conversations: ConversationSummary[]): BriefView {
  const byId = new Map(conversations.map((conversation) => [conversation.threadId, conversation]))
  const entries: BriefEntry[] = []
  for (const threadId of new Set(brief.threadIds)) {
    const conversation = byId.get(threadId)
    if (conversation !== undefined) {
      entries.push({ conversation, noted: !conversation.inbox })
    }
  }
  return { brief, entries }
}

/** The newest brief, or null when there is none yet. */
export function latestBrief(briefs: SyncedDailyBrief[]): SyncedDailyBrief | null {
  return [...briefs].sort(compareBriefs)[0] ?? null
}

/** Newest first: by the end of the period, then by id. */
export function compareBriefs(a: SyncedDailyBrief, b: SyncedDailyBrief): number {
  return Date.parse(b.coversUntil) - Date.parse(a.coversUntil) || (a.id < b.id ? 1 : a.id > b.id ? -1 : 0)
}

/** Who a conversation of the brief is from: its newest sender. */
export function briefSender(conversation: ConversationSummary): Address | null {
  return conversation.senders[0] ?? null
}

/**
 * Groups the entries per smart inbox, in sidebar order, or per sender, the busiest first; entries keep the brief's
 * order within a group. Conversations without a smart inbox (any more) come last, as "Other".
 */
export function groupBrief(entries: BriefEntry[], grouping: BriefGrouping, smartInboxes: SyncedSmartInbox[]): BriefGroup[] {
  const groups = new Map<string, BriefGroup>()
  for (const entry of entries) {
    const [key, label] = grouping === 'smartInbox' ? smartInboxKey(entry.conversation, smartInboxes) : senderKey(entry.conversation)
    const group = groups.get(key) ?? { key, label, entries: [] }
    group.entries.push(entry)
    groups.set(key, group)
  }
  const list = [...groups.values()]
  if (grouping === 'smartInbox') {
    const position = new Map(smartInboxes.map((smartInbox) => [smartInbox.id, smartInbox.position]))
    return list.sort((a, b) => (position.get(a.key) ?? Number.MAX_SAFE_INTEGER) - (position.get(b.key) ?? Number.MAX_SAFE_INTEGER) || a.label.localeCompare(b.label))
  }
  return list.sort((a, b) => b.entries.length - a.entries.length || a.label.localeCompare(b.label))
}

/** How far the user is: noted entries out of all of them. */
export function briefProgress(entries: BriefEntry[]): { noted: number; total: number } {
  return { noted: entries.filter((entry) => entry.noted).length, total: entries.length }
}

/** The threads still to note, for "Mark all noted". */
export function threadsToNote(entries: BriefEntry[]): string[] {
  return entries.filter((entry) => !entry.noted).map((entry) => entry.conversation.threadId)
}

const OTHER = ''

function smartInboxKey(conversation: ConversationSummary, smartInboxes: SyncedSmartInbox[]): [string, string] {
  const smartInbox = smartInboxes.find((candidate) => candidate.id === conversation.smartInboxId)
  return smartInbox === undefined ? [OTHER, 'Other'] : [smartInbox.id, smartInbox.name]
}

function senderKey(conversation: ConversationSummary): [string, string] {
  const sender = briefSender(conversation)
  if (sender === null) {
    return [OTHER, 'Unknown sender']
  }
  return [sender.address.toLowerCase(), sender.name !== null && sender.name.trim() !== '' ? sender.name : sender.address]
}
