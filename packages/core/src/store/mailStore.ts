import type { AppliedAction, MessageAction } from '../actions'
import type { Verdict } from '../domain'
import type { ConversationSummary, ListScope } from '../sync/conversation'
import type { SyncStore } from '../sync/engine'
import type { Address, DraftAttachment, SyncedFolder, SyncedMailAccount, SyncedMessage, SyncedOutgoingMessage, SyncedSignature, SyncedSmartInbox, SyncedThread } from '../sync/types'

/** Someone the user exchanged mail with, for recipient suggestions. */
export interface Contact {
  /** Lower-cased address. */
  key: string
  address: string
  name: string | null
  /** Epoch ms of the newest message with them. */
  lastSeen: number
  messages: number
}

/**
 * A message being written: the synced draft plus what only this device knows. Edits are saved here first
 * (`pendingSave`) and then uploaded; `version` is the server version they build on, 0 while the draft has not
 * reached the server.
 */
export interface Draft {
  id: string
  mailAccountId: string | null
  to: Address[]
  cc: Address[]
  bcc: Address[]
  subject: string
  /** What the user writes, without signature and quote. */
  html: string
  /** The signature added below the text; null for none. */
  signatureId: string | null
  /** The original a reply or forward quotes, kept apart from the text. */
  quoteHtml: string
  inReplyToMessageId: string | null
  kind: 'new' | 'reply' | 'replyAll' | 'forward'
  version: number
  /** Edits on this device that have not reached the server yet. */
  pendingSave: boolean
  /** Set when another device saved a newer version while this one had unsaved edits: the version waiting there. */
  remoteVersion: number | null
  /** Set once sent: the draft stays until the message is confirmed sent, so a failure can be edited and resent. */
  outgoingId: string | null
  /** The outgoing status the user was last told about, so each outcome is announced once. */
  noticedStatus: string | null
  updatedAt: number
  /** The files on the server's copy, as last heard of; they change through the attachment endpoints. */
  attachments: DraftAttachment[]
}

/**
 * A message that needs the user and is new to them: unread, in an inbox, and judged `do` or `delegate` for the first
 * time, either when it arrived or when it was classified later.
 */
export interface Arrival {
  messageId: string
  threadId: string
  mailAccountId: string
  from: SyncedMessage['from']
  subject: string
  snippet: string
  receivedAt: string
  verdict: 'do' | 'delegate'
}

/** The copy is being closed (a sign-out or another user), so it takes no more writes. */
export class CopyClosingError extends Error {
  constructor() {
    super('The mail copy is closing.')
    this.name = 'CopyClosingError'
  }
}

/**
 * The signed-in user's mail kept on the device, whatever holds it: IndexedDB in the browser, SQLite on the desktop and
 * the phone. Screens read from it only; the sync engine writes the delta into it.
 */
export interface MailStore extends SyncStore {
  /**
   * Makes an action show at once, before the server confirms it, the way the server will make it (see actionResult).
   *
   * @throws MissingFolderError when an account has nothing to archive or trash into; nothing changes then
   */
  applyAction(messageIds: string[], action: MessageAction, folderId?: string, fromFolderId?: string): Promise<AppliedAction>
  /**
   * Puts back what {@link applyAction} changed, on the messages the delta has not updated since. It is only given
   * messages whose request the server did not take, so a delta can only have brought them someone else's change.
   */
  revertAction(applied: AppliedAction): Promise<void>
  /** The arrivals of the pages applied since the last call, oldest first. */
  takeArrivals(): Arrival[]
  /** By display name, then address. */
  mailAccounts(): Promise<SyncedMailAccount[]>
  folders(): Promise<SyncedFolder[]>
  /** By name. */
  signatures(): Promise<SyncedSignature[]>
  /** In sidebar order. */
  smartInboxes(): Promise<SyncedSmartInbox[]>
  /** Inbox threads, newest first; of one account, or of all accounts (the Unified Inbox). */
  inbox(mailAccountId: string | null, limit: number): Promise<ConversationSummary[]>
  /** The threads of a list (an inbox or a smart inbox), newest first. */
  list(scope: ListScope, limit: number): Promise<ConversationSummary[]>
  /**
   * Threads of a list matching a test, newest first, however many there are: for selecting beyond the part of the list
   * that is loaded.
   */
  listThreadIds(scope: ListScope, matches: (conversation: ConversationSummary) => boolean): Promise<string[]>
  /**
   * What a selection of threads holds now: the ones still in the list on screen, and their accounts and senders
   * (lower-case addresses).
   */
  describeThreads(scope: ListScope, threadIds: string[]): Promise<{ present: string[]; accounts: string[]; senders: string[] }>
  /** The messages of these threads, for acting on whole conversations. */
  messageIdsOfThreads(threadIds: string[]): Promise<string[]>
  /** The messages of each thread. */
  messagesByThread(threadIds: string[]): Promise<Map<string, string[]>>
  /** Inbox threads with unread mail, per account; every account is listed, with 0 when it has none. */
  unreadInboxCounts(): Promise<Map<string, number>>
  /** Threads with unread mail per smart inbox, among those it lists; every smart inbox is listed. */
  unreadSmartInboxCounts(): Promise<Map<string, number>>
  /**
   * Shows the user's choice at once, before the server confirms it: the thread moves to the smart inbox with the
   * verdict. Returns the thread as it was, for {@link restoreThread} when the server refuses; null when it is gone.
   */
  classifyThread(threadId: string, smartInboxId: string, verdict: Verdict): Promise<SyncedThread | null>
  /** Puts a thread back as it was before {@link classifyThread}, unless the delta changed it since. */
  restoreThread(previous: SyncedThread, optimistic: { smartInboxId: string; verdict: Verdict }): Promise<void>
  /** Outgoing mail that is not settled: scheduled, on its way, or failed. Newest first. */
  outbox(): Promise<SyncedOutgoingMessage[]>
  outgoing(id: string): Promise<SyncedOutgoingMessage | null>
  /** Contacts whose address or a word of whose name starts with the query, most recent first. */
  suggestContacts(query: string, limit: number): Promise<Contact[]>
  saveDraft(draft: Draft): Promise<void>
  /**
   * Changes a draft as one step: nothing else written to it in between (a sync, another save). `change` gets the
   * draft as stored (null when there is none) and returns what to store, or null to leave it as it is; it must not
   * wait for anything. Returns what is stored afterwards.
   */
  changeDraft(id: string, change: (stored: Draft | null) => Draft | null): Promise<Draft | null>
  /** Most recently changed first. */
  drafts(): Promise<Draft[]>
  draft(id: string): Promise<Draft | null>
  deleteDraft(id: string): Promise<void>
  /** A thread with its messages oldest first, or null when it no longer exists. */
  thread(threadId: string): Promise<{ thread: SyncedThread; messages: SyncedMessage[] } | null>
  /** Finishes the writes under way and closes the copy; it takes no more writes. */
  close(): Promise<void>
}
