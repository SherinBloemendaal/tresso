import { deleteDB, openDB, type DBSchema, type IDBPDatabase, type IDBPTransaction, type StoreNames } from 'idb'
import {
  actionResult,
  arrivalOf,
  contactMatches,
  deletedDraft,
  mergeContact,
  peopleOn,
  storedDraft,
  summarizeConversation,
  syncedDraft,
  type AppliedAction,
  type Arrival,
  type ChangePage,
  type Contact,
  type ConversationSummary,
  type Draft,
  type MailStore,
  type MessageAction,
  type SyncedFolder,
  type SyncedMailAccount,
  type SyncedMessage,
  type SyncedOutgoingMessage,
  type SyncedSignature,
  type SyncedDraft,
  type SyncedThread,
} from '@tresso/core'

export type { Arrival, Contact, Draft } from '@tresso/core'

/** What a thread looks like in a mailbox list. */
export type Conversation = ConversationSummary

/**
 * A conversation as IndexedDB keeps it: booleans cannot be index keys, so the inbox flags are numbers, which the
 * lists read through indexes and so stay fast however much mail is stored.
 */
interface StoredConversation extends Omit<ConversationSummary, 'inbox'> {
  /** 1 when a message of the thread is in an inbox folder. */
  inbox: 0 | 1
  /** 1 when that thread is in an inbox and has unread mail. */
  unreadInbox: 0 | 1
}

function toSummary(stored: StoredConversation): ConversationSummary {
  return {
    threadId: stored.threadId,
    mailAccountId: stored.mailAccountId,
    subject: stored.subject,
    snippet: stored.snippet,
    lastMessageAt: stored.lastMessageAt,
    messageCount: stored.messageCount,
    unreadCount: stored.unreadCount,
    flagged: stored.flagged,
    hasAttachments: stored.hasAttachments,
    senders: stored.senders,
    inbox: stored.inbox === 1,
  }
}

interface MailSchema extends DBSchema {
  meta: { key: string; value: number }
  mailAccounts: { key: string; value: SyncedMailAccount }
  folders: { key: string; value: SyncedFolder; indexes: { byAccount: string } }
  threads: { key: string; value: SyncedThread; indexes: { byAccount: string } }
  messages: {
    key: string
    value: SyncedMessage
    indexes: { byThread: string; byAccount: string; byFolder: string }
  }
  outgoing: { key: string; value: SyncedOutgoingMessage; indexes: { byAccount: string; bySendAt: string } }
  contacts: { key: string; value: Contact; indexes: { byLastSeen: number } }
  drafts: { key: string; value: Draft; indexes: { byUpdatedAt: number } }
  conversations: {
    key: string
    value: StoredConversation
    indexes: {
      byAccount: string
      byInboxDate: [number, number]
      byAccountInboxDate: [string, number, number]
      byAccountUnreadInbox: [string, number]
    }
  }
  signatures: { key: string; value: SyncedSignature }
}

type Stores = StoreNames<MailSchema>[]
type WriteTx = IDBPTransaction<MailSchema, Stores, 'readwrite'>

const ALL_STORES: Stores = ['meta', 'mailAccounts', 'folders', 'threads', 'messages', 'conversations', 'outgoing', 'contacts', 'signatures', 'drafts']
const VERSION = 4

export function databaseName(userId: string): string {
  return `tresso-mail-${userId}`
}

/** Removes everything stored for a user, e.g. on sign-out. */
export function deleteMailDatabase(userId: string): Promise<void> {
  return deleteDB(databaseName(userId))
}

/** The signed-in user's mail in the browser's IndexedDB. The desktop app keeps it in SQLite instead (SqliteMailStore). */
export class IndexedDbMailStore implements MailStore {
  /** Collected by {@link apply}, handed out by {@link takeArrivals}. */
  private arrivals: Arrival[] = []

  private constructor(private readonly db: IDBPDatabase<MailSchema>) {}

  static async open(userId: string): Promise<IndexedDbMailStore> {
    const db = await openDB<MailSchema>(databaseName(userId), VERSION, {
      async upgrade(database, oldVersion, _newVersion, transaction) {
        if (oldVersion < 1) {
          createVersion1(database)
        }
        if (oldVersion < 2) {
          const outgoing = database.createObjectStore('outgoing', { keyPath: 'id' })
          outgoing.createIndex('byAccount', 'mailAccountId')
          outgoing.createIndex('bySendAt', 'sendAt')
          database.createObjectStore('contacts', { keyPath: 'key' }).createIndex('byLastSeen', 'lastSeen')
          database.createObjectStore('drafts', { keyPath: 'id' }).createIndex('byUpdatedAt', 'updatedAt')
        }
        if (oldVersion < 3) {
          database.createObjectStore('signatures', { keyPath: 'id' })
          // Accounts stored before signatures existed have no default; the server gave every account null.
          const accounts = transaction.objectStore('mailAccounts')
          for (let cursor = await accounts.openCursor(); cursor !== null; cursor = await cursor.continue()) {
            await cursor.update({ ...cursor.value, defaultSignatureId: cursor.value.defaultSignatureId ?? null })
          }
        }
        if (oldVersion >= 2 && oldVersion < 4) {
          // Drafts used to stay on the device; they are uploaded now, so nothing written before is lost.
          const drafts = transaction.objectStore('drafts')
          for (let cursor = await drafts.openCursor(); cursor !== null; cursor = await cursor.continue()) {
            await cursor.update({ ...cursor.value, version: 0, pendingSave: cursor.value.outgoingId === null, remoteVersion: null })
          }
        }
        // Outgoing mail, contacts, signatures and drafts come from the delta: an existing copy syncs again from the start.
        if (oldVersion >= 1) {
          await transaction.objectStore('meta').delete('cursor')
        }
      },
    })

    return new IndexedDbMailStore(db)
  }

  async close(): Promise<void> {
    this.db.close()
  }

  async cursor(): Promise<number> {
    return (await this.db.get('meta', 'cursor')) ?? 0
  }

  async apply(page: ChangePage): Promise<void> {
    const tx = this.db.transaction(ALL_STORES, 'readwrite')
    const affected = new Set<string>()

    for (const deleted of page.deleted) {
      switch (deleted.type) {
        case 'mailAccount':
          await deleteAccount(tx, deleted.id)
          break
        case 'folder':
          for (const message of await tx.objectStore('messages').index('byFolder').getAll(deleted.id)) {
            affected.add(message.threadId)
          }
          await tx.objectStore('folders').delete(deleted.id)
          break
        case 'thread':
          await tx.objectStore('threads').delete(deleted.id)
          affected.add(deleted.id)
          break
        case 'message': {
          const message = await tx.objectStore('messages').get(deleted.id)
          if (message !== undefined) {
            affected.add(message.threadId)
            await tx.objectStore('messages').delete(deleted.id)
          }
          break
        }
        case 'outgoingMessage':
          await tx.objectStore('outgoing').delete(deleted.id)
          break
        case 'signature':
          await tx.objectStore('signatures').delete(deleted.id)
          break
        case 'draft': {
          const local = await tx.objectStore('drafts').get(deleted.id)
          if (local !== undefined) {
            const kept = deletedDraft(storedDraft(local))
            await (kept === null ? tx.objectStore('drafts').delete(deleted.id) : tx.objectStore('drafts').put(kept))
          }
          break
        }
      }
    }

    for (const account of page.mailAccounts) {
      await tx.objectStore('mailAccounts').put(account)
    }
    for (const folder of page.folders) {
      const previous = await tx.objectStore('folders').get(folder.id)
      await tx.objectStore('folders').put(folder)
      if (previous !== undefined && previous.role !== folder.role) {
        for (const message of await tx.objectStore('messages').index('byFolder').getAll(folder.id)) {
          affected.add(message.threadId)
        }
      }
    }
    for (const signature of page.signatures) {
      await tx.objectStore('signatures').put(signature)
    }
    for (const thread of page.threads) {
      await tx.objectStore('threads').put(thread)
      affected.add(thread.id)
    }
    const inboxFolders = new Set(
      (await tx.objectStore('folders').getAll()).filter((folder) => folder.role === 'inbox').map((folder) => folder.id),
    )
    const arrivals: Arrival[] = []
    for (const message of page.messages) {
      const previous = await tx.objectStore('messages').get(message.id)
      if (previous !== undefined && previous.threadId !== message.threadId) {
        affected.add(previous.threadId)
      }
      await tx.objectStore('messages').put(message)
      affected.add(message.threadId)
      if (previous === undefined) {
        await rememberContacts(tx, message)
      }
      const arrival = arrivalOf(previous, message, inboxFolders)
      if (arrival !== null) {
        arrivals.push(arrival)
      }
    }
    for (const outgoing of page.outgoingMessages) {
      await tx.objectStore('outgoing').put(outgoing)
    }
    for (const draft of page.drafts) {
      await applyDraft(tx, draft)
    }

    for (const threadId of affected) {
      await refreshConversation(tx, threadId, inboxFolders)
    }

    await tx.objectStore('meta').put(page.cursor, 'cursor')
    await tx.done
    this.arrivals.push(...arrivals)
  }

  /**
   * Makes an action show at once, before the server confirms it: flags change, and archived, trashed or moved
   * messages change folders (archiving takes them out of the inbox into Archive, or All Mail where there is none).
   * The delta brings the server's version right after.
   *
   * @throws MissingFolderError when an account has nothing to archive or trash into; nothing changes then
   */
  async applyAction(messageIds: string[], action: MessageAction, folderId?: string, fromFolderId?: string): Promise<AppliedAction> {
    const tx = this.db.transaction(ALL_STORES, 'readwrite')
    const folders = await tx.objectStore('folders').getAll()
    const accounts = await tx.objectStore('mailAccounts').getAll()
    const inboxFolders = new Set(folders.filter((folder) => folder.role === 'inbox').map((folder) => folder.id))

    const before: SyncedMessage[] = []
    for (const id of messageIds) {
      const message = await tx.objectStore('messages').get(id)
      if (message !== undefined) {
        before.push(message)
      }
    }
    const after = actionResult(before, action, { folders, accounts, folderId, fromFolderId })

    const affected = new Set<string>()
    for (const message of after) {
      await tx.objectStore('messages').put(message)
      affected.add(message.threadId)
    }
    for (const threadId of affected) {
      await refreshConversation(tx, threadId, inboxFolders)
    }
    await tx.done

    return { before, after }
  }

  /**
   * Puts back what {@link applyAction} changed, on the messages the delta has not updated since. It is only given
   * messages whose request the server did not take, so a delta can only have brought them someone else's change.
   */
  async revertAction(applied: AppliedAction): Promise<void> {
    const tx = this.db.transaction(ALL_STORES, 'readwrite')
    const inboxFolders = new Set(
      (await tx.objectStore('folders').getAll()).filter((folder) => folder.role === 'inbox').map((folder) => folder.id),
    )
    const affected = new Set<string>()
    for (const [index, previous] of applied.before.entries()) {
      const current = await tx.objectStore('messages').get(previous.id)
      if (current !== undefined && JSON.stringify(current) === JSON.stringify(applied.after[index])) {
        await tx.objectStore('messages').put(previous)
        affected.add(previous.threadId)
      }
    }
    for (const threadId of affected) {
      await refreshConversation(tx, threadId, inboxFolders)
    }
    await tx.done
  }

  /** The arrivals of the pages applied since the last call, oldest first. */
  takeArrivals(): Arrival[] {
    const taken = this.arrivals
    this.arrivals = []
    return taken
  }

  async mailAccounts(): Promise<SyncedMailAccount[]> {
    const accounts = await this.db.getAll('mailAccounts')

    return accounts.sort((a, b) => a.displayName.localeCompare(b.displayName) || a.emailAddress.localeCompare(b.emailAddress))
  }

  async folders(): Promise<SyncedFolder[]> {
    return this.db.getAll('folders')
  }

  async signatures(): Promise<SyncedSignature[]> {
    const signatures = await this.db.getAll('signatures')

    return signatures.sort((a, b) => a.name.localeCompare(b.name) || a.id.localeCompare(b.id))
  }

  /** Inbox threads, newest first; of one account, or of all accounts (the Unified Inbox). */
  async inbox(mailAccountId: string | null, limit: number): Promise<ConversationSummary[]> {
    const tx = this.db.transaction('conversations')
    const index = mailAccountId === null ? tx.store.index('byInboxDate') : tx.store.index('byAccountInboxDate')
    const range =
      mailAccountId === null
        ? IDBKeyRange.bound([1, -Infinity], [1, Infinity])
        : IDBKeyRange.bound([mailAccountId, 1, -Infinity], [mailAccountId, 1, Infinity])

    const conversations: ConversationSummary[] = []
    for (let cursor = await index.openCursor(range, 'prev'); cursor !== null && conversations.length < limit; cursor = await cursor.continue()) {
      conversations.push(toSummary(cursor.value))
    }

    return conversations
  }

  /**
   * Inbox threads (of one account, or of all) matching a test, newest first, however many there are: for selecting
   * beyond the part of the list that is loaded.
   */
  async inboxThreadIds(mailAccountId: string | null, matches: (conversation: ConversationSummary) => boolean): Promise<string[]> {
    const tx = this.db.transaction('conversations')
    const index = mailAccountId === null ? tx.store.index('byInboxDate') : tx.store.index('byAccountInboxDate')
    const range =
      mailAccountId === null
        ? IDBKeyRange.bound([1, -Infinity], [1, Infinity])
        : IDBKeyRange.bound([mailAccountId, 1, -Infinity], [mailAccountId, 1, Infinity])

    const ids: string[] = []
    for (let cursor = await index.openCursor(range, 'prev'); cursor !== null; cursor = await cursor.continue()) {
      if (matches(toSummary(cursor.value))) {
        ids.push(cursor.value.threadId)
      }
    }

    return ids
  }

  /**
   * What a selection of threads holds now: the ones still in the inbox on screen (of one account, or of all), and
   * their accounts and senders (lower-case addresses).
   */
  async describeThreads(mailAccountId: string | null, threadIds: string[]): Promise<{ present: string[]; accounts: string[]; senders: string[] }> {
    const tx = this.db.transaction('conversations')
    const present: string[] = []
    const accounts = new Set<string>()
    const senders = new Set<string>()
    for (const threadId of threadIds) {
      const conversation = await tx.store.get(threadId)
      if (conversation === undefined || conversation.inbox !== 1 || (mailAccountId !== null && conversation.mailAccountId !== mailAccountId)) {
        continue
      }
      present.push(threadId)
      accounts.add(conversation.mailAccountId)
      for (const sender of conversation.senders) {
        senders.add(sender.address.toLowerCase())
      }
    }

    return { present, accounts: [...accounts], senders: [...senders] }
  }

  /** The messages of these threads, for acting on whole conversations. */
  async messageIdsOfThreads(threadIds: string[]): Promise<string[]> {
    return [...(await this.messagesByThread(threadIds)).values()].flat()
  }

  /** The messages of each thread, read in one transaction however many threads there are. */
  async messagesByThread(threadIds: string[]): Promise<Map<string, string[]>> {
    const tx = this.db.transaction('messages')
    const byThread = new Map<string, string[]>()
    for (const threadId of threadIds) {
      byThread.set(threadId, await tx.store.index('byThread').getAllKeys(threadId))
    }
    await tx.done

    return byThread
  }

  /** Inbox threads with unread mail, per account. */
  async unreadInboxCounts(): Promise<Map<string, number>> {
    const tx = this.db.transaction(['mailAccounts', 'conversations'])
    const counts = new Map<string, number>()
    for (const accountId of await tx.objectStore('mailAccounts').getAllKeys()) {
      counts.set(accountId, await tx.objectStore('conversations').index('byAccountUnreadInbox').count([accountId, 1]))
    }

    return counts
  }

  /** Outgoing mail that is not settled: scheduled, on its way, or failed. Newest first. */
  async outbox(): Promise<SyncedOutgoingMessage[]> {
    const all = await this.db.getAll('outgoing')

    return all.filter((message) => message.status !== 'sent' && message.status !== 'cancelled').sort((a, b) => b.sendAt.localeCompare(a.sendAt))
  }

  async outgoing(id: string): Promise<SyncedOutgoingMessage | null> {
    return (await this.db.get('outgoing', id)) ?? null
  }

  /** Contacts matching the start of a name or address, most recent first. */
  async suggestContacts(query: string, limit: number): Promise<Contact[]> {
    const result: Contact[] = []
    const index = this.db.transaction('contacts').store.index('byLastSeen')
    for (let cursor = await index.openCursor(null, 'prev'); cursor !== null && result.length < limit; cursor = await cursor.continue()) {
      if (contactMatches(cursor.value, query)) {
        result.push(cursor.value)
      }
    }

    return result
  }

  async saveDraft(draft: Draft): Promise<void> {
    await this.db.put('drafts', draft)
  }

  async changeDraft(id: string, change: (stored: Draft | null) => Draft | null): Promise<Draft | null> {
    const tx = this.db.transaction('drafts', 'readwrite')
    const found = await tx.store.get(id)
    const stored = found === undefined ? null : storedDraft(found)
    const next = change(stored)
    if (next !== null) {
      await tx.store.put(next)
    }
    await tx.done
    return next ?? stored
  }

  async drafts(): Promise<Draft[]> {
    return (await this.db.getAllFromIndex('drafts', 'byUpdatedAt')).reverse().map(storedDraft)
  }

  async draft(id: string): Promise<Draft | null> {
    const draft = await this.db.get('drafts', id)
    return draft === undefined ? null : storedDraft(draft)
  }

  async deleteDraft(id: string): Promise<void> {
    await this.db.delete('drafts', id)
  }

  async thread(threadId: string): Promise<{ thread: SyncedThread; messages: SyncedMessage[] } | null> {
    const tx = this.db.transaction(['threads', 'messages'])
    const thread = await tx.objectStore('threads').get(threadId)
    if (thread === undefined) {
      return null
    }
    const messages = await tx.objectStore('messages').index('byThread').getAll(threadId)
    messages.sort((a, b) => Date.parse(a.receivedAt) - Date.parse(b.receivedAt))

    return { thread, messages }
  }
}

function createVersion1(database: IDBPDatabase<MailSchema>): void {
  database.createObjectStore('meta')
  database.createObjectStore('mailAccounts', { keyPath: 'id' })
  database.createObjectStore('folders', { keyPath: 'id' }).createIndex('byAccount', 'mailAccountId')
  database.createObjectStore('threads', { keyPath: 'id' }).createIndex('byAccount', 'mailAccountId')
  const messages = database.createObjectStore('messages', { keyPath: 'id' })
  messages.createIndex('byThread', 'threadId')
  messages.createIndex('byAccount', 'mailAccountId')
  messages.createIndex('byFolder', 'folderIds', { multiEntry: true })
  const conversations = database.createObjectStore('conversations', { keyPath: 'threadId' })
  conversations.createIndex('byAccount', 'mailAccountId')
  conversations.createIndex('byInboxDate', ['inbox', 'lastMessageAt'])
  conversations.createIndex('byAccountInboxDate', ['mailAccountId', 'inbox', 'lastMessageAt'])
  conversations.createIndex('byAccountUnreadInbox', ['mailAccountId', 'unreadInbox'])
}

async function deleteAccount(tx: WriteTx, accountId: string): Promise<void> {
  await tx.objectStore('mailAccounts').delete(accountId)
  // Drafts outlive the account they were written from; they keep their text and need a new one to send.
  for (const draft of await tx.objectStore('drafts').getAll()) {
    if (draft.mailAccountId === accountId) {
      await tx.objectStore('drafts').put({ ...draft, mailAccountId: null })
    }
  }
  for (const store of ['folders', 'threads', 'messages', 'conversations', 'outgoing'] as const) {
    const index = tx.objectStore(store).index('byAccount')
    for (let cursor = await index.openCursor(accountId); cursor !== null; cursor = await cursor.continue()) {
      await cursor.delete()
    }
  }
}

async function applyDraft(tx: WriteTx, draft: SyncedDraft): Promise<void> {
  const local = await tx.objectStore('drafts').get(draft.id)
  const next = syncedDraft(local === undefined ? undefined : storedDraft(local), draft)
  if (next !== null) {
    await tx.objectStore('drafts').put(next)
  }
}

async function rememberContacts(tx: WriteTx, message: SyncedMessage): Promise<void> {
  const at = Date.parse(message.receivedAt)
  for (const { key, person } of peopleOn(message)) {
    await tx.objectStore('contacts').put(mergeContact(await tx.objectStore('contacts').get(key), key, person, at))
  }
}

async function refreshConversation(tx: WriteTx, threadId: string, inboxFolders: Set<string>): Promise<void> {
  const thread = await tx.objectStore('threads').get(threadId)
  const messages = thread === undefined ? [] : await tx.objectStore('messages').index('byThread').getAll(threadId)
  const summary = thread === undefined ? null : summarizeConversation(thread, messages, inboxFolders)
  if (summary === null) {
    await tx.objectStore('conversations').delete(threadId)
    return
  }

  await tx.objectStore('conversations').put({
    ...summary,
    inbox: summary.inbox ? 1 : 0,
    unreadInbox: summary.inbox && summary.unreadCount > 0 ? 1 : 0,
  })
}
