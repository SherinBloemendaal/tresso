import { deleteDB, openDB, type DBSchema, type IDBPDatabase, type IDBPTransaction, type StoreNames } from 'idb'
import type {
  Address,
  ChangePage,
  SyncedFolder,
  SyncedMailAccount,
  SyncedMessage,
  SyncedDraft,
  SyncedOutgoingMessage,
  SyncedSignature,
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
    value: Conversation
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
        case 'outgoingMessage':
          await tx.objectStore('outgoing').delete(deleted.id)
          break
        case 'signature':
          await tx.objectStore('signatures').delete(deleted.id)
          break
        case 'draft': {
          const local = await tx.objectStore('drafts').get(deleted.id)
          if (local?.outgoingId) {
            // Sent: kept here until the outcome is known, so a failure can be edited and sent again.
            await tx.objectStore('drafts').put({ ...local, version: 0, pendingSave: false, remoteVersion: null })
          } else if (local !== undefined && !local.pendingSave) {
            await tx.objectStore('drafts').delete(deleted.id)
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
    }
    for (const outgoing of page.outgoingMessages) {
      await tx.objectStore('outgoing').put(outgoing)
    }
    for (const draft of page.drafts) {
      await applyDraft(tx, draft)
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

  async folders(): Promise<SyncedFolder[]> {
    return this.db.getAll('folders')
  }

  async signatures(): Promise<SyncedSignature[]> {
    const signatures = await this.db.getAll('signatures')

    return signatures.sort((a, b) => a.name.localeCompare(b.name) || a.id.localeCompare(b.id))
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
    const needle = query.trim().toLowerCase()
    const result: Contact[] = []
    const index = this.db.transaction('contacts').store.index('byLastSeen')
    for (let cursor = await index.openCursor(null, 'prev'); cursor !== null && result.length < limit; cursor = await cursor.continue()) {
      const contact = cursor.value
      const words = [contact.key, ...(contact.name ?? '').toLowerCase().split(/\s+/)]
      if (needle === '' || words.some((word) => word.startsWith(needle))) {
        result.push(contact)
      }
    }

    return result
  }

  async saveDraft(draft: Draft): Promise<void> {
    await this.db.put('drafts', draft)
  }

  async drafts(): Promise<Draft[]> {
    return (await this.db.getAllFromIndex('drafts', 'byUpdatedAt')).reverse()
  }

  async draft(id: string): Promise<Draft | null> {
    return (await this.db.get('drafts', id)) ?? null
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

/**
 * A draft from the server. Unsaved edits on this device are never overwritten: when the server moved past the
 * version they build on, the newer version is noted as a conflict for the user to settle.
 */
async function applyDraft(tx: WriteTx, draft: SyncedDraft): Promise<void> {
  const local = await tx.objectStore('drafts').get(draft.id)
  if (local?.pendingSave) {
    if (draft.version > local.version) {
      await tx.objectStore('drafts').put({ ...local, remoteVersion: draft.version })
    }
    return
  }
  await tx.objectStore('drafts').put({
    ...draft,
    updatedAt: Date.parse(draft.updatedAt),
    pendingSave: false,
    remoteVersion: null,
    outgoingId: local?.outgoingId ?? null,
    noticedStatus: local?.noticedStatus ?? null,
  })
}

/** Everyone on a message becomes a suggestion, the newest name winning. */
async function rememberContacts(tx: WriteTx, message: SyncedMessage): Promise<void> {
  const at = Date.parse(message.receivedAt)
  const people = [message.from, ...message.recipients.to, ...message.recipients.cc]
  for (const person of people) {
    const key = person.address.trim().toLowerCase()
    if (key === '' || !key.includes('@')) continue
    const known = await tx.objectStore('contacts').get(key)
    const newer = known === undefined || at >= known.lastSeen
    await tx.objectStore('contacts').put({
      key,
      address: newer ? person.address : known.address,
      name: (newer ? person.name : known.name) ?? known?.name ?? null,
      lastSeen: Math.max(at, known?.lastSeen ?? 0),
      messages: (known?.messages ?? 0) + 1,
    })
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
