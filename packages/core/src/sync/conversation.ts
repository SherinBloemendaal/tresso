import type { Address, SyncedMessage, SyncedThread } from './types'

/** How many senders a conversation shows. */
export const SENDERS_PER_CONVERSATION = 3

/**
 * What a thread looks like in a mailbox list, derived from its messages. Clients store it whenever one of the messages
 * changes, so lists read a few dozen rows however much mail is stored.
 */
export interface ConversationSummary {
  threadId: string
  mailAccountId: string
  subject: string
  snippet: string
  /** Epoch ms of the newest message. */
  lastMessageAt: number
  messageCount: number
  unreadCount: number
  flagged: boolean
  hasAttachments: boolean
  /** Newest senders first, without duplicates. */
  senders: Address[]
  /** Whether a message of the thread is in an inbox folder. */
  inbox: boolean
}

/** The summary of a thread, or null when it has no messages (anymore). */
export function summarizeConversation(thread: SyncedThread, messages: SyncedMessage[], inboxFolderIds: Set<string>): ConversationSummary | null {
  if (messages.length === 0) {
    return null
  }
  const newestFirst = [...messages].sort((a, b) => Date.parse(b.receivedAt) - Date.parse(a.receivedAt))
  const newest = newestFirst[0]!
  const senders: Address[] = []
  for (const message of newestFirst) {
    if (senders.length < SENDERS_PER_CONVERSATION && !senders.some((s) => s.address.toLowerCase() === message.from.address.toLowerCase())) {
      senders.push(message.from)
    }
  }

  return {
    threadId: thread.id,
    mailAccountId: thread.mailAccountId,
    subject: thread.subject,
    snippet: newest.snippet,
    lastMessageAt: Date.parse(newest.receivedAt),
    messageCount: messages.length,
    unreadCount: messages.filter((message) => !message.flags.seen).length,
    flagged: messages.some((message) => message.flags.flagged),
    hasAttachments: messages.some((message) => message.hasAttachments),
    senders,
    inbox: messages.some((message) => message.folderIds.some((id) => inboxFolderIds.has(id))),
  }
}
