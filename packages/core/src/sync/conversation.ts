import type { Verdict } from '../domain'
import type { Address, SyncedFolder, SyncedMessage, SyncedSmartInbox, SyncedThread } from './types'

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
  /** Whether an attachment of the thread was blocked because it contains malware. */
  attachmentsBlocked: boolean
  /** Newest senders first, without duplicates. */
  senders: Address[]
  /**
   * Whether the inboxes list it: a message of the thread is in an inbox folder, the thread is not in the Spam smart
   * inbox (spam stays out of the inboxes, also when the server left it there), and it does not wait for a batch.
   */
  inbox: boolean
  /** Whether its smart inbox lists it: a message of the thread is in an inbox or junk folder, and it is not held. */
  active: boolean
  /** Epoch ms of the batch it waits for; null when it is delivered. A held conversation is in no list. */
  heldUntil: number | null
  /** Epoch ms of the batch that delivered it; null when it arrived as it came. */
  batchedAt: number | null
  smartInboxId: string | null
  verdict: Verdict | null
  classificationReason: string | null
}

/** Where the lists take conversations from, read once for a batch of summaries. */
export interface ListFolders {
  inboxFolderIds: Set<string>
  spamFolderIds: Set<string>
  /** The built-in Spam smart inbox; null until the smart inboxes arrived. */
  spamSmartInboxId: string | null
}

/** The summary of a thread, or null when it has no messages (anymore). */
export function summarizeConversation(thread: SyncedThread, messages: SyncedMessage[], lists: ListFolders): ConversationSummary | null {
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

  const inInbox = messages.some((message) => message.folderIds.some((id) => lists.inboxFolderIds.has(id)))
  const inJunk = messages.some((message) => message.folderIds.some((id) => lists.spamFolderIds.has(id)))
  // Threads stored before classification reached clients have no such fields yet.
  const smartInboxId = thread.smartInboxId ?? null
  const heldUntil = thread.heldUntil ? Date.parse(thread.heldUntil) : null
  const held = heldUntil !== null

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
    attachmentsBlocked: messages.some((message) => message.attachmentScan === 'infected'),
    senders,
    inbox: inInbox && (smartInboxId === null || smartInboxId !== lists.spamSmartInboxId) && !held,
    active: (inInbox || inJunk) && !held,
    heldUntil,
    batchedAt: thread.batchedAt ? Date.parse(thread.batchedAt) : null,
    smartInboxId,
    verdict: thread.verdict ?? null,
    classificationReason: thread.classificationReason ?? null,
  }
}

/**
 * A list of conversations: the inbox of one account or of every account (`mailAccountId` null), a smart inbox, which
 * spans every account, or the batches: conversations a batch delivered that are still in an inbox, newest batch first.
 */
export type ListScope = { kind: 'inbox'; mailAccountId: string | null } | { kind: 'smartInbox'; smartInboxId: string } | { kind: 'batch' }

/** Whether a list shows a conversation. */
export function inList(conversation: ConversationSummary, scope: ListScope): boolean {
  if (scope.kind === 'smartInbox') {
    return conversation.smartInboxId === scope.smartInboxId && conversation.active
  }
  if (scope.kind === 'batch') {
    return conversation.batchedAt !== null && conversation.inbox
  }

  return conversation.inbox && (scope.mailAccountId === null || conversation.mailAccountId === scope.mailAccountId)
}

/** What a thread can be listed by, from the folders and smart inboxes stored. */
export function listFolders(folders: Pick<SyncedFolder, 'id' | 'role'>[], smartInboxes: Pick<SyncedSmartInbox, 'id' | 'builtin'>[]): ListFolders {
  return {
    inboxFolderIds: new Set(folders.filter((folder) => folder.role === 'inbox').map((folder) => folder.id)),
    spamFolderIds: new Set(folders.filter((folder) => folder.role === 'spam').map((folder) => folder.id)),
    spamSmartInboxId: smartInboxes.find((smartInbox) => smartInbox.builtin === 'spam')?.id ?? null,
  }
}
