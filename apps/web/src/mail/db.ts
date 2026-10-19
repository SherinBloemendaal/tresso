import { deleteDB, openDB, type DBSchema, type IDBPDatabase, type IDBPTransaction, type StoreNames } from 'idb'
import type {
  Address,
  ChangePage,
  SyncedFolder,
  SyncedMailAccount,
  SyncedMessage,
  SyncedThread,
  SyncStore,
} from '@tresso/core'

/**
 * What a thread looks like in a mailbox list, derived from its messages whenever one of them changes. Lists read only
 * these, through indexes, so they stay fast however much mail is stored.
 */
export interface Conversation {
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
  /** 1 when a message of the thread is in an inbox folder. Booleans cannot be index keys. */
  inbox: 0 | 1
  /** 1 when that thread is in an inbox and has unread mail. */
  unreadInbox: 0 | 1
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
  conversations: {
    key: string
    value: Conversation
    indexes: {
      byAccount: string
      byInboxDate: [number, number]
      byAccountInboxDate: [string, number, number]
      byAccountUnreadInbox: [string, number]
    }
  }
}

type Stores = StoreNames<MailSchema>[]
type WriteTx = IDBPTransaction<MailSchema, Stores, 'readwrite'>

const ALL_STORES: Stores = ['meta', 'mailAccounts', 'folders', 'threads', 'messages', 'conversations']
const VERSION = 1
const SENDERS_PER_CONVERSATION = 3

export function databaseName(userId: string): string {
  return `tresso-mail-${userId}`
}

/** Removes everything stored for a user, e.g. on sign-out. */
export function deleteMailDatabase(userId: string): Promise<void> {
  return deleteDB(databaseName(userId))
}

export class MailDatabase implements SyncStore {
  private constructor(private readonly db: IDBPDatabase<MailSchema>) {}

  static async open(userId: string): Promise<MailDatabase> {
    const db = await openDB<MailSchema>(databaseName(userId), VERSION, {
      upgrade(database) {
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
      },
    })

    return new MailDatabase(db)
  }

  close(): void {
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
    for (const thread of page.threads) {
      await tx.objectStore('threads').put(thread)
      affected.add(thread.id)
    }
    for (const message of page.messages) {
      const previous = await tx.objectStore('messages').get(message.id)
      if (previous !== undefined && previous.threadId !== message.threadId) {
        affected.add(previous.threadId)
      }
      await tx.objectStore('messages').put(message)
      affected.add(message.threadId)
    }

    const inboxFolders = new Set(
      (await tx.objectStore('folders').getAll()).filter((folder) => folder.role === 'inbox').map((folder) => folder.id),
    )
    for (const threadId of affected) {
      await refreshConversation(tx, threadId, inboxFolders)
    }

    await tx.objectStore('meta').put(page.cursor, 'cursor')
    await tx.done
  }

  async mailAccounts(): Promise<SyncedMailAccount[]> {
    const accounts = await this.db.getAll('mailAccounts')

    return accounts.sort((a, b) => a.displayName.localeCompare(b.displayName) || a.emailAddress.localeCompare(b.emailAddress))
  }

  /** Inbox threads, newest first; of one account, or of all accounts (the Unified Inbox). */
  async inbox(mailAccountId: string | null, limit: number): Promise<Conversation[]> {
    const tx = this.db.transaction('conversations')
    const index = mailAccountId === null ? tx.store.index('byInboxDate') : tx.store.index('byAccountInboxDate')
    const range =
      mailAccountId === null
        ? IDBKeyRange.bound([1, -Infinity], [1, Infinity])
        : IDBKeyRange.bound([mailAccountId, 1, -Infinity], [mailAccountId, 1, Infinity])

    const conversations: Conversation[] = []
    for (let cursor = await index.openCursor(range, 'prev'); cursor !== null && conversations.length < limit; cursor = await cursor.continue()) {
      conversations.push(cursor.value)
    }

    return conversations
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

async function deleteAccount(tx: WriteTx, accountId: string): Promise<void> {
  await tx.objectStore('mailAccounts').delete(accountId)
  for (const store of ['folders', 'threads', 'messages', 'conversations'] as const) {
    const index = tx.objectStore(store).index('byAccount')
    for (let cursor = await index.openCursor(accountId); cursor !== null; cursor = await cursor.continue()) {
      await cursor.delete()
    }
  }
}

async function refreshConversation(tx: WriteTx, threadId: string, inboxFolders: Set<string>): Promise<void> {
  const thread = await tx.objectStore('threads').get(threadId)
  const messages = thread === undefined ? [] : await tx.objectStore('messages').index('byThread').getAll(threadId)
  if (thread === undefined || messages.length === 0) {
    await tx.objectStore('conversations').delete(threadId)
    return
  }

  messages.sort((a, b) => Date.parse(b.receivedAt) - Date.parse(a.receivedAt))
  const newest = messages[0]!
  const senders: Address[] = []
  for (const message of messages) {
    if (senders.length < SENDERS_PER_CONVERSATION && !senders.some((s) => s.address.toLowerCase() === message.from.address.toLowerCase())) {
      senders.push(message.from)
    }
  }
  const unreadCount = messages.filter((message) => !message.flags.seen).length
  const inbox = messages.some((message) => message.folderIds.some((id) => inboxFolders.has(id))) ? 1 : 0

  await tx.objectStore('conversations').put({
    threadId,
    mailAccountId: thread.mailAccountId,
    subject: thread.subject,
    snippet: newest.snippet,
    lastMessageAt: Date.parse(newest.receivedAt),
    messageCount: messages.length,
    unreadCount,
    flagged: messages.some((message) => message.flags.flagged),
    hasAttachments: messages.some((message) => message.hasAttachments),
    senders,
    inbox,
    unreadInbox: inbox === 1 && unreadCount > 0 ? 1 : 0,
  })
}
