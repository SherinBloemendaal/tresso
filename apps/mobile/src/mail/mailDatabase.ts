import {
  actionResult,
  summarizeConversation,
  type AppliedAction,
  type MessageAction,
  type ChangePage,
  type ConversationSummary,
  type SyncedFolder,
  type SyncedMailAccount,
  type SyncedMessage,
  type SyncedThread,
  type SyncStore,
} from '@tresso/core'
import type { Sql } from '@/mail/sql'

/**
 * Raised whenever what the mobile app keeps changes shape. A copy made by an older version is emptied and synced
 * again from the start, so nothing it skipped stays missing.
 */
export const SCHEMA_VERSION = 1

const SCHEMA = `
CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY NOT NULL, value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS mail_accounts (id TEXT PRIMARY KEY NOT NULL, json TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS folders (id TEXT PRIMARY KEY NOT NULL, mail_account_id TEXT NOT NULL, role TEXT NOT NULL, json TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS threads (id TEXT PRIMARY KEY NOT NULL, mail_account_id TEXT NOT NULL, json TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS messages (id TEXT PRIMARY KEY NOT NULL, thread_id TEXT NOT NULL, mail_account_id TEXT NOT NULL, json TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS messages_by_thread ON messages (thread_id);
CREATE TABLE IF NOT EXISTS message_folders (message_id TEXT NOT NULL, folder_id TEXT NOT NULL, PRIMARY KEY (message_id, folder_id));
CREATE INDEX IF NOT EXISTS message_folders_by_folder ON message_folders (folder_id);
CREATE TABLE IF NOT EXISTS conversations (
  thread_id TEXT PRIMARY KEY NOT NULL,
  mail_account_id TEXT NOT NULL,
  inbox INTEGER NOT NULL,
  unread INTEGER NOT NULL,
  last_message_at INTEGER NOT NULL,
  json TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS conversations_by_inbox ON conversations (inbox, last_message_at);
CREATE INDEX IF NOT EXISTS conversations_by_account_inbox ON conversations (mail_account_id, inbox, last_message_at);
`

const TABLES = ['mail_accounts', 'folders', 'threads', 'messages', 'message_folders', 'conversations', 'meta']

/**
 * The signed-in user's mail on the device: mail accounts, folders, threads and messages from the delta, plus a
 * summary per thread for the lists. Outgoing mail, drafts and signatures are not kept yet; when they are, the schema
 * version goes up and the copy syncs again from the start.
 */
export class MailDatabase implements SyncStore {
  /** Set once the copy is being closed: pages that arrive later are refused. */
  private shutting = false
  /** Pages being stored, which closing waits for. */
  private readonly writing = new Set<Promise<void>>()

  private constructor(private readonly sql: Sql) {}

  /** Refuses further pages and resolves once the ones being stored are in, so the database can be closed. */
  async shut(): Promise<void> {
    this.shutting = true
    await Promise.allSettled([...this.writing])
  }

  static async open(sql: Sql): Promise<MailDatabase> {
    await sql.exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY NOT NULL, value TEXT NOT NULL)')
    const version = await sql.first<{ value: string }>('SELECT value FROM meta WHERE key = ?', ['schema'])
    if (version === null || Number(version.value) !== SCHEMA_VERSION) {
      // A copy of another shape is dropped and built again, in one go, so tables never keep an old shape.
      await sql.transaction(async (tx) => {
        for (const table of TABLES) {
          await tx.exec(`DROP TABLE IF EXISTS ${table}`)
        }
        await tx.exec(SCHEMA)
        await tx.run('INSERT INTO meta (key, value) VALUES (?, ?)', ['schema', String(SCHEMA_VERSION)])
      })
    }
    return new MailDatabase(sql)
  }

  async cursor(): Promise<number> {
    const row = await this.sql.first<{ value: string }>('SELECT value FROM meta WHERE key = ?', ['cursor'])
    return row === null ? 0 : Number(row.value)
  }

  apply(page: ChangePage): Promise<void> {
    return this.write(() => this.store(page))
  }

  /** Every write goes through here: refused once the copy is closing, and waited for when it closes. */
  private async write<T>(task: () => Promise<T>): Promise<T> {
    if (this.shutting) {
      throw new Error('The mail copy is closing.')
    }
    const writing = task()
    const settled = writing.then(
      () => undefined,
      () => undefined,
    )
    this.writing.add(settled)
    try {
      return await writing
    } finally {
      this.writing.delete(settled)
    }
  }

  private async store(page: ChangePage): Promise<void> {
    await this.sql.transaction(async (tx) => {
      const affected = new Set<string>()

      for (const deleted of page.deleted) {
        switch (deleted.type) {
          case 'mailAccount':
            await deleteAccount(tx, deleted.id)
            break
          case 'folder':
            for (const row of await tx.all<{ thread_id: string }>(
              'SELECT m.thread_id FROM messages m JOIN message_folders f ON f.message_id = m.id WHERE f.folder_id = ?',
              [deleted.id],
            )) {
              affected.add(row.thread_id)
            }
            await tx.run('DELETE FROM message_folders WHERE folder_id = ?', [deleted.id])
            await tx.run('DELETE FROM folders WHERE id = ?', [deleted.id])
            break
          case 'thread':
            await tx.run('DELETE FROM threads WHERE id = ?', [deleted.id])
            affected.add(deleted.id)
            break
          case 'message': {
            const row = await tx.first<{ thread_id: string }>('SELECT thread_id FROM messages WHERE id = ?', [deleted.id])
            if (row !== null) {
              affected.add(row.thread_id)
              await tx.run('DELETE FROM message_folders WHERE message_id = ?', [deleted.id])
              await tx.run('DELETE FROM messages WHERE id = ?', [deleted.id])
            }
            break
          }
        }
      }

      for (const account of page.mailAccounts) {
        await tx.run('INSERT OR REPLACE INTO mail_accounts (id, json) VALUES (?, ?)', [account.id, JSON.stringify(account)])
      }
      for (const folder of page.folders) {
        const previous = await tx.first<{ role: string }>('SELECT role FROM folders WHERE id = ?', [folder.id])
        await tx.run('INSERT OR REPLACE INTO folders (id, mail_account_id, role, json) VALUES (?, ?, ?, ?)', [folder.id, folder.mailAccountId, folder.role, JSON.stringify(folder)])
        if (previous !== null && previous.role !== folder.role) {
          for (const row of await tx.all<{ thread_id: string }>(
            'SELECT m.thread_id FROM messages m JOIN message_folders f ON f.message_id = m.id WHERE f.folder_id = ?',
            [folder.id],
          )) {
            affected.add(row.thread_id)
          }
        }
      }
      for (const thread of page.threads) {
        await tx.run('INSERT OR REPLACE INTO threads (id, mail_account_id, json) VALUES (?, ?, ?)', [thread.id, thread.mailAccountId, JSON.stringify(thread)])
        affected.add(thread.id)
      }
      for (const message of page.messages) {
        const previous = await tx.first<{ thread_id: string }>('SELECT thread_id FROM messages WHERE id = ?', [message.id])
        if (previous !== null && previous.thread_id !== message.threadId) {
          affected.add(previous.thread_id)
        }
        await writeMessage(tx, message)
        affected.add(message.threadId)
      }

      const inboxFolders = await inboxFolderIds(tx)
      for (const threadId of affected) {
        await refreshConversation(tx, threadId, inboxFolders)
      }
      await tx.run('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)', ['cursor', String(page.cursor)])
    })
  }

  /**
   * Makes an action show at once, before the server confirms it, the way the server will make it (see actionResult).
   *
   * @throws MissingFolderError when an account has nothing to archive or trash into; nothing changes then
   */
  applyAction(messageIds: string[], action: MessageAction, folderId?: string, fromFolderId?: string): Promise<AppliedAction> {
    return this.write(() => this.storeAction(messageIds, action, folderId, fromFolderId))
  }

  private async storeAction(messageIds: string[], action: MessageAction, folderId?: string, fromFolderId?: string): Promise<AppliedAction> {
    let applied: AppliedAction = { before: [], after: [] }
    await this.sql.transaction(async (tx) => {
      const folders = (await tx.all<{ json: string }>('SELECT json FROM folders')).map((row) => JSON.parse(row.json) as SyncedFolder)
      const accounts = (await tx.all<{ json: string }>('SELECT json FROM mail_accounts')).map((row) => JSON.parse(row.json) as SyncedMailAccount)
      const before: SyncedMessage[] = []
      for (const id of messageIds) {
        const row = await tx.first<{ json: string }>('SELECT json FROM messages WHERE id = ?', [id])
        if (row !== null) {
          before.push(JSON.parse(row.json) as SyncedMessage)
        }
      }
      const after = actionResult(before, action, { folders, accounts, folderId, fromFolderId })
      await this.rewrite(tx, after)
      applied = { before, after }
    })
    return applied
  }

  /**
   * Puts back what {@link applyAction} changed, on the messages the delta has not updated since. It is only given
   * messages whose request the server did not take, so a delta can only have brought them someone else's change.
   */
  revertAction(applied: AppliedAction): Promise<void> {
    return this.write(() => this.storeRevert(applied))
  }

  private async storeRevert(applied: AppliedAction): Promise<void> {
    await this.sql.transaction(async (tx) => {
      const restore: SyncedMessage[] = []
      for (const [index, previous] of applied.before.entries()) {
        const row = await tx.first<{ json: string }>('SELECT json FROM messages WHERE id = ?', [previous.id])
        if (row !== null && row.json === JSON.stringify(applied.after[index])) {
          restore.push(previous)
        }
      }
      await this.rewrite(tx, restore)
    })
  }

  private async rewrite(tx: Sql, messages: SyncedMessage[]): Promise<void> {
    const affected = new Set<string>()
    for (const message of messages) {
      await writeMessage(tx, message)
      affected.add(message.threadId)
    }
    const inboxFolders = await inboxFolderIds(tx)
    for (const threadId of affected) {
      await refreshConversation(tx, threadId, inboxFolders)
    }
  }

  async mailAccounts(): Promise<SyncedMailAccount[]> {
    const accounts = (await this.sql.all<{ json: string }>('SELECT json FROM mail_accounts')).map((row) => JSON.parse(row.json) as SyncedMailAccount)
    return accounts.sort((a, b) => a.displayName.localeCompare(b.displayName) || a.emailAddress.localeCompare(b.emailAddress))
  }

  async folders(): Promise<SyncedFolder[]> {
    return (await this.sql.all<{ json: string }>('SELECT json FROM folders')).map((row) => JSON.parse(row.json) as SyncedFolder)
  }

  /** Inbox threads, newest first; of one account, or of all accounts (the Unified Inbox). */
  async inbox(mailAccountId: string | null, limit: number): Promise<ConversationSummary[]> {
    const rows =
      mailAccountId === null
        ? await this.sql.all<{ json: string }>('SELECT json FROM conversations WHERE inbox = 1 ORDER BY last_message_at DESC LIMIT ?', [limit])
        : await this.sql.all<{ json: string }>('SELECT json FROM conversations WHERE mail_account_id = ? AND inbox = 1 ORDER BY last_message_at DESC LIMIT ?', [mailAccountId, limit])
    return rows.map((row) => JSON.parse(row.json) as ConversationSummary)
  }

  /** Inbox threads with unread mail, per account. */
  async unreadInboxCounts(): Promise<Map<string, number>> {
    const rows = await this.sql.all<{ mail_account_id: string; count: number }>(
      'SELECT mail_account_id, COUNT(*) AS count FROM conversations WHERE inbox = 1 AND unread = 1 GROUP BY mail_account_id',
    )
    return new Map(rows.map((row) => [row.mail_account_id, Number(row.count)]))
  }

  /** A thread with its messages oldest first, or null when it no longer exists. */
  async thread(threadId: string): Promise<{ thread: SyncedThread; messages: SyncedMessage[] } | null> {
    const row = await this.sql.first<{ json: string }>('SELECT json FROM threads WHERE id = ?', [threadId])
    if (row === null) {
      return null
    }
    const messages = (await this.sql.all<{ json: string }>('SELECT json FROM messages WHERE thread_id = ?', [threadId])).map((m) => JSON.parse(m.json) as SyncedMessage)
    messages.sort((a, b) => Date.parse(a.receivedAt) - Date.parse(b.receivedAt))
    return { thread: JSON.parse(row.json) as SyncedThread, messages }
  }
}

async function writeMessage(tx: Sql, message: SyncedMessage): Promise<void> {
  await tx.run('INSERT OR REPLACE INTO messages (id, thread_id, mail_account_id, json) VALUES (?, ?, ?, ?)', [message.id, message.threadId, message.mailAccountId, JSON.stringify(message)])
  await tx.run('DELETE FROM message_folders WHERE message_id = ?', [message.id])
  for (const folderId of new Set(message.folderIds)) {
    await tx.run('INSERT INTO message_folders (message_id, folder_id) VALUES (?, ?)', [message.id, folderId])
  }
}

async function inboxFolderIds(tx: Sql): Promise<Set<string>> {
  return new Set((await tx.all<{ id: string }>("SELECT id FROM folders WHERE role = 'inbox'")).map((row) => row.id))
}

async function deleteAccount(tx: Sql, accountId: string): Promise<void> {
  await tx.run('DELETE FROM message_folders WHERE message_id IN (SELECT id FROM messages WHERE mail_account_id = ?)', [accountId])
  for (const table of ['messages', 'threads', 'folders', 'conversations']) {
    await tx.run(`DELETE FROM ${table} WHERE mail_account_id = ?`, [accountId])
  }
  await tx.run('DELETE FROM mail_accounts WHERE id = ?', [accountId])
}

async function refreshConversation(tx: Sql, threadId: string, inboxFolders: Set<string>): Promise<void> {
  const thread = await tx.first<{ json: string }>('SELECT json FROM threads WHERE id = ?', [threadId])
  const messages = thread === null ? [] : (await tx.all<{ json: string }>('SELECT json FROM messages WHERE thread_id = ?', [threadId])).map((row) => JSON.parse(row.json) as SyncedMessage)
  const summary = thread === null ? null : summarizeConversation(JSON.parse(thread.json) as SyncedThread, messages, inboxFolders)
  if (summary === null) {
    await tx.run('DELETE FROM conversations WHERE thread_id = ?', [threadId])
    return
  }
  await tx.run('INSERT OR REPLACE INTO conversations (thread_id, mail_account_id, inbox, unread, last_message_at, json) VALUES (?, ?, ?, ?, ?, ?)', [
    threadId,
    summary.mailAccountId,
    summary.inbox ? 1 : 0,
    summary.unreadCount > 0 ? 1 : 0,
    summary.lastMessageAt,
    JSON.stringify(summary),
  ])
}
